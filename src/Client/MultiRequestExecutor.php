<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Client;

use CurlHandle;
use CurlMultiHandle;
use RuntimeException;
use Throwable;

/**
 * Runs cURL transfers concurrently while keeping a strict global concurrency limit.
 *
 * @internal
 */
final class MultiRequestExecutor
{
    /**
     * @param array<int, array<string, mixed>> $jobs
     * @param callable(array<string, mixed>): CurlHandle $createHandle
     * @param callable(array<string, mixed>, string|false, int, string, array): MultiRequestStep $completeTransfer
     * @param callable(array<string, mixed>, Throwable): mixed|null $handleFailure
     *
     * @return array<int|string, mixed> Results are returned in the original input order.
     */
    public function execute(
        array $jobs,
        int $concurrency,
        callable $createHandle,
        callable $completeTransfer,
        ?callable $handleFailure = null
    ): array {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Concurrency must be at least one.');
        }

        if ($jobs === []) {
            return [];
        }

        $multiHandle = curl_multi_init();
        if (!$multiHandle instanceof CurlMultiHandle) {
            throw new RuntimeException('Failed to initialize the cURL multi handle.');
        }

        $queue = array_values($jobs);
        $active = [];
        $results = [];
        $originalKeys = array_map(
            static fn(array $job): int|string => $job['key'],
            $jobs
        );

        try {
            $this->configureMultiHandle($multiHandle, $concurrency);

            while ($queue !== [] || $active !== []) {
                $this->fillAvailableSlots(
                    $multiHandle,
                    $queue,
                    $active,
                    $results,
                    $concurrency,
                    $createHandle,
                    $handleFailure
                );

                if ($active === []) {
                    continue;
                }

                $running = 0;
                $multiStatus = $this->executeTransfers($multiHandle, $running);

                if ($multiStatus !== $this->getMultiOkCode()) {
                    $this->failOutstandingJobs(
                        $multiHandle,
                        $queue,
                        $active,
                        $results,
                        $this->createMultiError($multiStatus),
                        $handleFailure
                    );
                    break;
                }

                $completedAny = $this->collectCompletedTransfers(
                    $multiHandle,
                    $active,
                    $queue,
                    $results,
                    $completeTransfer,
                    $handleFailure
                );

                if ($active !== [] && !$completedAny) {
                    if ($running === 0) {
                        $this->failOutstandingJobs(
                            $multiHandle,
                            $queue,
                            $active,
                            $results,
                            new RuntimeException(
                                'The cURL multi stack stopped while requests were still registered.'
                            ),
                            $handleFailure
                        );

                        break;
                    }

                    $selected = curl_multi_select($multiHandle, 1.0);

                    if ($selected === -1) {
                        usleep(10_000);
                    }
                }
            }
        } finally {
            $this->closeActiveHandles($multiHandle, $active);
            curl_multi_close($multiHandle);
        }

        $orderedResults = [];

        foreach ($originalKeys as $key) {
            $orderedResults[$key] = array_key_exists($key, $results)
                ? $results[$key]
                : new RuntimeException(sprintf(
                    'No result was produced for request "%s".',
                    (string)$key
                ));
        }

        return $orderedResults;
    }

    private function configureMultiHandle(CurlMultiHandle $multiHandle, int $concurrency): void
    {
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            $option = constant('CURLMOPT_MAX_TOTAL_CONNECTIONS');

            if (!curl_multi_setopt($multiHandle, $option, $concurrency)) {
                throw new RuntimeException('Failed to configure the cURL multi connection limit.');
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<int, array{handle: CurlHandle, job: array<string, mixed>}> $active
     * @param array<int|string, mixed> $results
     */
    private function fillAvailableSlots(
        CurlMultiHandle $multiHandle,
        array &$queue,
        array &$active,
        array &$results,
        int $concurrency,
        callable $createHandle,
        ?callable $handleFailure
    ): void {
        while ($queue !== [] && count($active) < $concurrency) {
            $job = array_shift($queue);
            $handle = null;

            try {
                $handle = $createHandle($job);

                if (!$handle instanceof CurlHandle) {
                    throw new RuntimeException('The transfer factory did not return a valid cURL handle.');
                }

                $status = curl_multi_add_handle($multiHandle, $handle);

                if ($status !== $this->getMultiOkCode()) {
                    throw $this->createMultiError($status);
                }

                $active[spl_object_id($handle)] = [
                    'handle' => $handle,
                    'job' => $job,
                ];
            } catch (Throwable $error) {
                if ($handle instanceof CurlHandle) {
                    curl_close($handle);
                }

                $results[$job['key']] = $this->handleFailure($job, $error, $handleFailure);
            }
        }
    }

    private function executeTransfers(CurlMultiHandle $multiHandle, int &$running): int
    {
        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === $this->getCallMultiPerformCode());

        return $status;
    }

    /**
     * @param array<int, array{handle: CurlHandle, job: array<string, mixed>}> $active
     * @param array<int, array<string, mixed>> $queue
     * @param array<int|string, mixed> $results
     */
    private function collectCompletedTransfers(
        CurlMultiHandle $multiHandle,
        array &$active,
        array &$queue,
        array &$results,
        callable $completeTransfer,
        ?callable $handleFailure
    ): bool {
        $completedAny = false;

        while (($message = curl_multi_info_read($multiHandle)) !== false) {
            if (($message['msg'] ?? null) !== $this->getDoneMessageCode()) {
                continue;
            }

            $handle = $message['handle'] ?? null;
            if (!$handle instanceof CurlHandle) {
                continue;
            }

            $handleId = spl_object_id($handle);
            if (!isset($active[$handleId])) {
                continue;
            }

            $job = $active[$handleId]['job'];
            $content = curl_multi_getcontent($handle);
            $errorNumber = curl_errno($handle);
            $errorMessage = curl_error($handle);
            $info = curl_getinfo($handle);

            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
            unset($active[$handleId]);

            try {
                $step = $completeTransfer(
                    $job,
                    $content,
                    $errorNumber,
                    $errorMessage,
                    $info
                );

                if (!$step instanceof MultiRequestStep) {
                    throw new RuntimeException('The transfer completion handler returned an invalid result.');
                }

                if ($step->hasNextJob()) {
                    $queue[] = $step->getNextJob();
                } else {
                    $results[$job['key']] = $step->getResult();
                }
            } catch (Throwable $error) {
                $results[$job['key']] = $this->handleFailure($job, $error, $handleFailure);
            }

            $completedAny = true;
        }

        return $completedAny;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<int, array{handle: CurlHandle, job: array<string, mixed>}> $active
     * @param array<int|string, mixed> $results
     */
    private function failOutstandingJobs(
        CurlMultiHandle $multiHandle,
        array &$queue,
        array &$active,
        array &$results,
        RuntimeException $error,
        ?callable $handleFailure
    ): void {
        foreach ($active as $entry) {
            curl_multi_remove_handle($multiHandle, $entry['handle']);
            curl_close($entry['handle']);
            $results[$entry['job']['key']] = $this->handleFailure($entry['job'], $error, $handleFailure);
        }

        foreach ($queue as $job) {
            $results[$job['key']] = $this->handleFailure($job, $error, $handleFailure);
        }

        $active = [];
        $queue = [];
    }

    /**
     * @param array<int, array{handle: CurlHandle, job: array<string, mixed>}> $active
     */
    private function closeActiveHandles(CurlMultiHandle $multiHandle, array &$active): void
    {
        foreach ($active as $entry) {
            curl_multi_remove_handle($multiHandle, $entry['handle']);
            curl_close($entry['handle']);
        }

        $active = [];
    }

    /**
     * Give the caller a chance to process a request-specific failure.
     *
     * If the failure hook itself throws, that new exception becomes the result.
     *
     * @param array<string, mixed> $job
     */
    private function handleFailure(array $job, Throwable $error, ?callable $handleFailure): mixed
    {
        if ($handleFailure === null) {
            return $error;
        }

        try {
            return $handleFailure($job, $error);
        } catch (Throwable $failure) {
            return $failure;
        }
    }

    private function createMultiError(int $status): RuntimeException
    {
        $message = function_exists('curl_multi_strerror')
            ? curl_multi_strerror($status)
            : 'Unknown cURL multi error';

        return new RuntimeException(sprintf('cURL multi error %d: %s', $status, $message));
    }

    private function getMultiOkCode(): int
    {
        return defined('CURLM_OK') ? CURLM_OK : 0;
    }

    private function getCallMultiPerformCode(): int
    {
        return defined('CURLM_CALL_MULTI_PERFORM') ? CURLM_CALL_MULTI_PERFORM : -1;
    }

    private function getDoneMessageCode(): int
    {
        return defined('CURLMSG_DONE') ? CURLMSG_DONE : 1;
    }
}
