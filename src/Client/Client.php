<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Client;

use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Request\RequestInterface;
use Amirxd\UltraRequest\Response\HttpResponse;
use Amirxd\UltraRequest\Cookie\CookieJarInterface;
use Amirxd\UltraRequest\Proxy\ProxyInterface;
use Amirxd\UltraRequest\Auth\AuthManager;
use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Middleware\MiddlewareInterface;
use Amirxd\UltraRequest\Middleware\MiddlewarePipeline;
use Amirxd\UltraRequest\Middleware\RequestContext;

class Client
{
    private ?CookieJarInterface $cookieJar = null;
    private ?ProxyInterface $proxy = null;
    private array $defaultHeaders = [];
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $verifySSL = true;
    private ?AuthManager $auth = null;
    private array $curlOptions = [];
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }
    }

    /**
     * Register middleware at the end of the request pipeline.
     *
     * Request hooks run in registration order. Response and error hooks run in
     * reverse order. The same pipeline is used by send(), sendMany(), and pools.
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        foreach ($this->middlewares as $registered) {
            if ($registered === $middleware) {
                return $this;
            }
        }

        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Register middleware before all currently registered middleware.
     */
    public function prependMiddleware(MiddlewareInterface $middleware): self
    {
        $this->removeMiddleware($middleware);
        array_unshift($this->middlewares, $middleware);

        return $this;
    }

    /**
     * Alias for addMiddleware().
     */
    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        return $this->addMiddleware($middleware);
    }

    /**
     * Remove middleware by its object instance or fully-qualified class name.
     */
    public function removeMiddleware(MiddlewareInterface|string $middleware): self
    {
        $this->middlewares = array_values(array_filter(
            $this->middlewares,
            static function (MiddlewareInterface $registered) use ($middleware): bool {
                if ($middleware instanceof MiddlewareInterface) {
                    return $registered !== $middleware;
                }

                return get_class($registered) !== ltrim($middleware, '\\');
            }
        ));

        return $this;
    }

    /**
     * Remove every registered middleware.
     */
    public function clearMiddlewares(): self
    {
        $this->middlewares = [];

        return $this;
    }

    /**
     * @return MiddlewareInterface[]
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    private function createMiddlewarePipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline($this->middlewares);
    }

    /**
     * Resolve a middleware-handled failure or rethrow the resulting exception.
     */
    private function resolveMiddlewareError(
        MiddlewarePipeline $pipeline,
        RequestContext $context,
        \Throwable $error
    ): HttpResponse {
        $result = $pipeline->processError($context, $error);

        if ($result instanceof HttpResponse) {
            return $result;
        }

        throw $result;
    }

    public function withAuth(callable $callback): self
    {
        $manager = new AuthManager();
        $callback($manager);
        $this->auth = $manager->getAuth() !== null ? $manager : null;
        return $this;
    }

    public function withSSL(bool $verify = true): self
    {
        $this->verifySSL = $verify;

        return $this;
    }

    public function setSSLVerification(bool $verify): self
    {
        return $this->withSSL($verify);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        $this->auth = (new AuthManager())->basic($username, $password);
        return $this;
    }

    public function withBearerAuth(string $token): self
    {
        $this->auth = (new AuthManager())->bearer($token);
        return $this;
    }

    public function withApiKey(string $key, string $value, string $placement = 'header', ?string $prefix = null): self
    {
        $this->auth = (new AuthManager())->apiKey($key, $value, $placement, $prefix);
        return $this;
    }

    public function clearAuth(): self
    {
        $this->auth = null;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        if ($seconds < 1) throw new \InvalidArgumentException('Timeout must be at least one second.');
        $this->timeout = $seconds;
        return $this;
    }

    public function withConnectTimeout(int $seconds): self
    {
        if ($seconds < 1) throw new \InvalidArgumentException('Connect timeout must be at least one second.');
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function setProxy(ProxyInterface $proxy): self
    {
        $this->proxy = $proxy;

        return $this;
    }

    public function clearProxy(): self
    {
        $this->proxy = null;

        return $this;
    }

    public function withCookieJar(CookieJarInterface $cookieJar): self
    {
        $this->cookieJar = $cookieJar;

        return $this;
    }

    public function withDefaultHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
                throw new \InvalidArgumentException('Default headers must be string values.');
            }
            $this->validateHeader((string)$name, (string)$value);
            $this->setHeaderValue($this->defaultHeaders, (string)$name, (string)$value);
        }
        return $this;
    }

    public function withCurlOption(int $option, mixed $value): self
    {
        if ($option <= 0) throw new \InvalidArgumentException('Invalid cURL option.');
        if (in_array($option, $this->getProtectedCurlOptionIds(), true)) {
            throw new \InvalidArgumentException('This cURL option is controlled internally by UltraRequest.');
        }
        $this->curlOptions[$option] = $value;
        return $this;
    }

    private function getProtectedCurlOptionIds(): array
    {
        $names = [
            'CURLOPT_URL', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_UNRESTRICTED_AUTH',
            'CURLOPT_RETURNTRANSFER', 'CURLOPT_HEADER', 'CURLOPT_HTTPHEADER',
            'CURLOPT_COOKIE', 'CURLOPT_COOKIEFILE', 'CURLOPT_COOKIEJAR', 'CURLOPT_COOKIELIST',
            'CURLOPT_WRITEFUNCTION', 'CURLOPT_HEADERFUNCTION', 'CURLOPT_XFERINFOFUNCTION',
            'CURLOPT_PROGRESSFUNCTION', 'CURLOPT_POST', 'CURLOPT_HTTPGET', 'CURLOPT_NOBODY',
            'CURLOPT_CUSTOMREQUEST', 'CURLOPT_POSTFIELDS', 'CURLOPT_UPLOAD', 'CURLOPT_READFUNCTION',
            'CURLOPT_INFILESIZE', 'CURLOPT_INFILESIZE_LARGE', 'CURLOPT_PROTOCOLS',
            'CURLOPT_PROTOCOLS_STR', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS_STR',
        ];
        $ids = [];
        foreach ($names as $name) if (defined($name)) $ids[] = constant($name);
        return $ids;
    }

    /**
     * Send several requests concurrently.
     *
     * The returned array preserves the input keys and order. Each value is either
     * an HttpResponse or the Throwable raised for that individual request.
     * A failed request does not cancel the remaining requests in the pool.
     *
     * @param array<int|string, RequestInterface>|RequestPool $requests
     * @return array<int|string, HttpResponse|\Throwable>
     */
    public function sendMany(array|RequestPool $requests, int $concurrency = 5): array
    {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Concurrency must be at least one.');
        }

        $pool = $requests instanceof RequestPool
            ? $requests
            : (new RequestPool())->addMany($requests);

        if ($pool->isEmpty()) {
            return [];
        }

        $pipeline = $this->createMiddlewarePipeline();
        $jobs = [];
        $orderedKeys = [];
        $contexts = [];
        $immediateResults = [];

        foreach ($pool->all() as $key => $request) {
            if (!$request instanceof RequestInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Request at key "%s" must implement RequestInterface.',
                    (string)$key
                ));
            }

            $orderedKeys[] = $key;
            $context = new RequestContext($request, $key);
            $contexts[$key] = $context;

            try {
                $shortCircuitResponse = $pipeline->prepare($context);

                if ($shortCircuitResponse !== null) {
                    $immediateResults[$key] = $pipeline->processResponse(
                        $context,
                        $shortCircuitResponse
                    );
                    continue;
                }

                $preparedRequest = $context->getRequest();
                $jobs[] = [
                    'key' => $key,
                    'request' => $preparedRequest,
                    'initial_url' => $preparedRequest->getUrl(),
                    'redirects' => 0,
                    'context' => $context,
                ];
            } catch (\Throwable $error) {
                try {
                    $immediateResults[$key] = $this->resolveMiddlewareError(
                        $pipeline,
                        $context,
                        $error
                    );
                } catch (\Throwable $unhandledError) {
                    $immediateResults[$key] = $unhandledError;
                }
            }
        }

        $transportResults = [];

        if ($jobs !== []) {
            $executor = new MultiRequestExecutor();

            try {
                $transportResults = $executor->execute(
                    $jobs,
                    $concurrency,
                    fn(array $job): \CurlHandle => $this->createMultiRequestHandle($job),
                    fn(
                        array $job,
                        string|false $content,
                        int $errorNumber,
                        string $errorMessage,
                        array $info
                    ): MultiRequestStep => $this->completeConcurrentRequest(
                        $pipeline,
                        $job,
                        $content,
                        $errorNumber,
                        $errorMessage,
                        $info
                    ),
                    static function (array $job, \Throwable $error) use ($pipeline): HttpResponse|\Throwable {
                        $context = $job['context'] ?? null;

                        if (!$context instanceof RequestContext) {
                            return $error;
                        }

                        $context->setAttribute('ultra.middleware.error_handled', true);

                        return $pipeline->processError($context, $error);
                    }
                );
            } catch (\Throwable $poolError) {
                // A failure to initialize or configure curl_multi is shared by
                // the whole batch, but each request still gets its own error hook.
                foreach ($jobs as $job) {
                    $context = $job['context'];
                    $context->setAttribute('ultra.middleware.error_handled', true);
                    $transportResults[$job['key']] = $pipeline->processError($context, $poolError);
                }
            }
        }

        $results = [];

        foreach ($orderedKeys as $key) {
            if (array_key_exists($key, $immediateResults)) {
                $results[$key] = $immediateResults[$key];
                continue;
            }

            $context = $contexts[$key];
            $result = $transportResults[$key] ?? new \RuntimeException(sprintf(
                'No result was produced for request "%s".',
                (string)$key
            ));

            if ($result instanceof HttpResponse) {
                // Successful concurrent responses and recovered failures have
                // already passed through their appropriate middleware lifecycle.
                $results[$key] = $result;
                continue;
            }

            if ($result instanceof \Throwable) {
                if ($context->getAttribute('ultra.middleware.error_handled', false)) {
                    $results[$key] = $result;
                } else {
                    // This is a defensive fallback for an executor result that
                    // did not pass through its per-job failure callback.
                    $results[$key] = $pipeline->processError($context, $result);
                }
                continue;
            }

            $unexpectedResult = new \UnexpectedValueException(
                'Concurrent executor returned an unsupported result type.'
            );
            $results[$key] = $pipeline->processError($context, $unexpectedResult);
        }

        return $results;
    }

    /**
     * Execute a previously configured RequestPool.
     *
     * @return array<int|string, HttpResponse|\Throwable>
     */
    public function sendPool(RequestPool $pool, int $concurrency = 5): array
    {
        return $this->sendMany($pool, $concurrency);
    }

    /** @param array<string, mixed> $job */
    private function createMultiRequestHandle(array $job): \CurlHandle
    {
        $request = $job['request'];
        $requestUrl = $request->getUrl();
        $allowAuth = $this->sameOrigin($job['initial_url'], $requestUrl);
        $effectiveUrl = $this->addAuthQuery($requestUrl, $allowAuth);

        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('Failed to initialize cURL for a concurrent request.');
        }

        try {
            $options = $this->buildBaseOptions($effectiveUrl, $request);
            $options = $this->addHeaders($options, $request, $allowAuth);
            $options = $this->addCookies($options, $effectiveUrl, $request);
            $options = $this->addProxy($options);
            $options = $this->addCustomCurlOptions($options, $allowAuth);

            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_HEADER] = true;
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;

            $this->applyCurlOptions($handle, $options);

            return $handle;
        } catch (\Throwable $error) {
            curl_close($handle);
            throw $error;
        }
    }

    /**
     * Complete one concurrent transfer and invoke response middleware immediately
     * when the logical request (including redirects) has finished.
     *
     * @param array<string, mixed> $job
     */
    private function completeConcurrentRequest(
        MiddlewarePipeline $pipeline,
        array $job,
        string|false $content,
        int $errorNumber,
        string $errorMessage,
        array $info
    ): MultiRequestStep {
        $step = $this->completeMultiRequest(
            $job,
            $content,
            $errorNumber,
            $errorMessage,
            $info
        );

        if ($step->hasNextJob()) {
            return $step;
        }

        $result = $step->getResult();

        if (!$result instanceof HttpResponse) {
            throw new \UnexpectedValueException('A completed concurrent request must contain an HttpResponse.');
        }

        $context = $job['context'] ?? null;

        if (!$context instanceof RequestContext) {
            throw new \LogicException('Concurrent request is missing its middleware context.');
        }

        return MultiRequestStep::complete(
            $pipeline->processResponse($context, $result)
        );
    }

    /**
     * @param array<string, mixed> $job
     */
    private function completeMultiRequest(
        array $job,
        string|false $content,
        int $errorNumber,
        string $errorMessage,
        array $info
    ): MultiRequestStep {
        if ($errorNumber !== 0 || $content === false) {
            throw new \RuntimeException(sprintf(
                'cURL error %d: %s',
                $errorNumber,
                $errorMessage !== '' ? $errorMessage : 'Unknown transfer error'
            ));
        }

        $headerSize = (int)($info['header_size'] ?? 0);
        $rawHeaders = substr($content, 0, $headerSize);
        $body = substr($content, $headerSize);
        $statusCode = (int)($info['http_code'] ?? 0);
        $response = new HttpResponse($body, $rawHeaders, $statusCode, $info);

        /** @var RequestInterface $request */
        $request = $job['request'];
        $requestUrl = $request->getUrl();
        $allowAuth = $this->sameOrigin($job['initial_url'], $requestUrl);
        $effectiveUrl = $this->addAuthQuery($requestUrl, $allowAuth);

        $this->saveResponseCookies($response, $effectiveUrl);

        if (!$request->followsRedirects() || !$this->isRedirectResponse($response)) {
            return MultiRequestStep::complete($response);
        }

        $location = $response->getHeader('Location');
        if ($location === null || trim($location) === '') {
            return MultiRequestStep::complete($response);
        }

        $maxRedirects = $request->getMaxRedirects() ?? 10;
        $redirectCount = (int)$job['redirects'];

        if ($redirectCount >= $maxRedirects) {
            throw new \RuntimeException(sprintf(
                'Maximum redirects (%d) exceeded for request "%s".',
                $maxRedirects,
                (string)$job['key']
            ));
        }

        $nextUrl = $this->resolveUrl($effectiveUrl, trim($location));
        $this->validateRedirectCredentials($effectiveUrl, $nextUrl);
        $nextRequest = $this->buildRedirectRequest($request, $nextUrl, $response);

        if (($job['context'] ?? null) instanceof RequestContext) {
            $job['context']->setRequest($nextRequest);
        }

        $nextJob = $job;
        $nextJob['request'] = $nextRequest;
        $nextJob['redirects'] = $redirectCount + 1;

        return MultiRequestStep::continueWith($nextJob);
    }

    public function send(RequestInterface $request): HttpResponse
    {
        $pipeline = $this->createMiddlewarePipeline();
        $context = new RequestContext($request);

        try {
            $shortCircuitResponse = $pipeline->prepare($context);

            if ($shortCircuitResponse !== null) {
                return $pipeline->processResponse($context, $shortCircuitResponse);
            }

            $response = $this->sendRequestWithRedirects($context);

            return $pipeline->processResponse($context, $response);
        } catch (\Throwable $error) {
            return $this->resolveMiddlewareError($pipeline, $context, $error);
        }
    }

    private function sendRequestWithRedirects(RequestContext $context): HttpResponse
    {
        $current = $context->getRequest();
        $initialUrl = $current->getUrl();
        $redirects = 0;
        $maxRedirects = $current->getMaxRedirects() ?? 10;

        while (true) {
            $url = $current->getUrl();
            $sameOrigin = $this->sameOrigin($initialUrl, $url);
            $effectiveUrl = $this->addAuthQuery($url, $sameOrigin);
            $response = $this->executeRequest($current, $effectiveUrl, $sameOrigin);

            if (!$current->followsRedirects() || !$this->isRedirectResponse($response)) {
                return $response;
            }

            $location = $response->getHeader('Location');

            if ($location === null || trim($location) === '') {
                return $response;
            }

            if ($redirects >= $maxRedirects) {
                throw new \RuntimeException("Maximum redirects ({$maxRedirects}) exceeded.");
            }

            $nextUrl = $this->resolveUrl($effectiveUrl, trim($location));
            $this->validateRedirectCredentials($effectiveUrl, $nextUrl);
            $current = $this->buildRedirectRequest($current, $nextUrl, $response);
            $context->setRequest($current);
            $redirects++;
        }
    }

    private function executeRequest(RequestInterface $request, string $url, bool $allowAuth): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) throw new \RuntimeException('Failed to initialize cURL.');

        try {
            $options = $this->buildBaseOptions($url, $request);
            $options = $this->addHeaders($options, $request, $allowAuth);
            $options = $this->addCookies($options, $url, $request);
            $options = $this->addProxy($options);
            $options = $this->addCustomCurlOptions($options, $allowAuth);
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;
            $this->applyCurlOptions($handle, $options);

            $raw = curl_exec($handle);
            if ($raw === false) {
                throw new \RuntimeException(sprintf('cURL error %d: %s', curl_errno($handle), curl_error($handle)));
            }

            $info = curl_getinfo($handle);
            $headerSize = (int)($info['header_size'] ?? 0);
            $rawHeaders = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            $response = new HttpResponse($body, $rawHeaders, (int)($info['http_code'] ?? 0), $info);
            $this->saveResponseCookies($response, $url);
            return $response;
        } finally {
            curl_close($handle);
        }
    }

    private function isRedirectResponse(HttpResponse $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    private function buildRedirectRequest(RequestInterface $original, string $newUrl, HttpResponse $response): Request
    {
        $status = $response->getStatusCode();
        $method = $original->getMethod();
        $dropBody = ($status === 303 && $method !== 'HEAD') || (in_array($status, [301, 302], true) && $method === 'POST');
        $newMethod = $dropBody ? 'GET' : $method;
        $sameOrigin = $this->sameOrigin($original->getUrl(), $newUrl);

        $next = new Request($newMethod, $newUrl);
        foreach ($original->getHeaders() as $name => $value) {
            $lower = strtolower((string)$name);
            if ($lower === 'host') continue;
            if ($dropBody && in_array($lower, ['content-length', 'content-type', 'content-encoding', 'transfer-encoding'], true)) continue;
            if (!$sameOrigin && $this->isSensitiveHeader($lower)) continue;
            $next->withHeader((string)$name, (string)$value);
        }
        if (!$dropBody && $original->getBody() !== null) $next->withBody($original->getBody());
        $next->withRedirects($original->followsRedirects());
        $next->withMaxRedirects($original->getMaxRedirects() ?? 10);
        return $next;
    }

    private function validateRedirectCredentials(string $sourceUrl, string $targetUrl): void
    {
        $source = parse_url($sourceUrl);
        $target = parse_url($targetUrl);
        if ($source === false || $target === false) throw new \RuntimeException('Invalid redirect URL.');

        $targetHasCredentials = isset($target['user']) || isset($target['pass']);
        if (!$targetHasCredentials) return;

        $sameCredentials = ($target['user'] ?? null) === ($source['user'] ?? null)
            && ($target['pass'] ?? null) === ($source['pass'] ?? null);
        if (!$this->sameOrigin($sourceUrl, $targetUrl) || !$sameCredentials) {
            throw new \RuntimeException('Redirect URLs cannot introduce or forward URL credentials to a different origin.');
        }
    }

    private function isSensitiveHeader(string $name): bool
    {
        return in_array($name, ['authorization', 'proxy-authorization', 'cookie', 'cookie2', 'set-cookie', 'x-api-key', 'api-key', 'x-auth'], true)
            || preg_match('/(^|[-_])(api[-_]?key|token|secret|password|credential)([-_]|$)/', $name) === 1;
    }

    private function sameOrigin(string $a, string $b): bool
    {
        $x = parse_url($a);
        $y = parse_url($b);
        if ($x === false || $y === false) return false;
        $portX = $x['port'] ?? (strtolower($x['scheme'] ?? '') === 'https' ? 443 : 80);
        $portY = $y['port'] ?? (strtolower($y['scheme'] ?? '') === 'https' ? 443 : 80);
        return strtolower($x['scheme'] ?? '') === strtolower($y['scheme'] ?? '')
            && strtolower($x['host'] ?? '') === strtolower($y['host'] ?? '')
            && $portX === $portY;
    }

    private function resolveUrl(string $baseUrl, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) return $location;
        $base = parse_url($baseUrl);
        if ($base === false || empty($base['scheme']) || empty($base['host'])) {
            throw new \RuntimeException('Cannot resolve redirect URL.');
        }
        $authority = $base['scheme'] . '://';
        if (isset($base['user'])) {
            $authority .= $base['user'];
            if (isset($base['pass'])) $authority .= ':' . $base['pass'];
            $authority .= '@';
        }
        $host = $base['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';
        $authority .= $host . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($location, '//')) return $base['scheme'] . ':' . $location;
        if (str_starts_with($location, '#')) {
            $withoutFragment = preg_replace('/#.*$/', '', $baseUrl);
            return $withoutFragment . $location;
        }

        $relative = parse_url($location);
        if ($relative === false) throw new \RuntimeException('Invalid redirect Location header.');
        if (isset($relative['scheme'])) return $location;
        if (isset($relative['host'])) return $base['scheme'] . ':' . $location;

        $basePath = $base['path'] ?? '/';
        if (str_starts_with($location, '?')) {
            $path = $basePath;
            $suffix = $location;
        } else {
            $relativePath = $relative['path'] ?? '';
            if ($relativePath === '') {
                $path = $basePath;
                $suffix = isset($relative['query']) ? '?' . $relative['query'] : (isset($relative['fragment']) ? '' : '');
            } else {
                $path = str_starts_with($relativePath, '/')
                    ? $relativePath
                    : rtrim(substr($basePath, 0, (int)strrpos($basePath, '/') + 1), '/') . '/' . $relativePath;
                $suffix = '';
            }
            if (isset($relative['query'])) $suffix = '?' . $relative['query'];
            if (isset($relative['fragment'])) $suffix .= '#' . $relative['fragment'];
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') { array_pop($segments); continue; }
            $segments[] = $segment;
        }
        $normalizedPath = '/' . implode('/', $segments);
        if (str_ends_with($path, '/') && !str_ends_with($normalizedPath, '/')) $normalizedPath .= '/';
        return $authority . $normalizedPath . $suffix;
    }

    private function addAuthQuery(string $url, bool $allowAuth): string
    {
        if (!$allowAuth || $this->auth === null) return $url;
        $params = $this->auth->getQueryParams();
        if ($params === []) return $url;
        return Request::get($url)->withQueryParams($params)->getUrl();
    }

    private function buildBaseOptions(string $url, RequestInterface $request): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_ENCODING => '',
        ];
        $method = strtoupper($request->getMethod());
        $body = $request->getBody();
        if ($body !== null && !is_string($body) && !is_array($body)) {
            throw new \InvalidArgumentException('HTTP request bodies must be strings or arrays accepted by cURL.');
        }
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;

        if ($method === 'HEAD') {
            if ($body !== null) throw new \InvalidArgumentException('HEAD requests cannot contain a request body.');
            $options[CURLOPT_NOBODY] = true;
        } elseif ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method === 'GET' && $body === null) {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            // Set the custom verb after POSTFIELDS so cURL does not silently
            // turn GET-with-body or a custom method into a POST request.
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        return $options;
    }

    private function addHeaders(array $options, RequestInterface $request, bool $allowAuth): array
    {
        $headers = [];
        foreach ($this->defaultHeaders as $name => $value) {
            if (strcasecmp((string)$name, 'Host') === 0) continue;
            if (!$allowAuth && $this->isSensitiveHeader(strtolower((string)$name))) continue;
            $this->setHeaderValue($headers, $name, $value);
        }
        if ($allowAuth && $this->auth !== null) {
            foreach ($this->auth->getHeaders() as $name => $value) {
                if (strcasecmp((string)$name, 'Host') === 0) continue;
                $this->setHeaderValue($headers, (string)$name, (string)$value);
            }
        }
        foreach ($request->getHeaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Host') === 0) continue;
            $this->setHeaderValue($headers, (string)$name, (string)$value);
        }
        if ($headers !== []) {
            $lines = [];
            foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
            $options[CURLOPT_HTTPHEADER] = $lines;
        }
        return $options;
    }

    private function setHeaderValue(array &$headers, string $name, string $value): void
    {
        $this->validateHeader($name, $value);
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp((string)$existing, $name) === 0) { $headers[$existing] = $value; return; }
        }
        $headers[$name] = $value;
    }

    private function validateHeader(string $name, string $value): void
    {
        if ($name === '' || preg_match('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', $name) || preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException('Invalid HTTP header name or value.');
        }
    }

    private function addCookies(array $options, string $url, RequestInterface $request): array
    {
        $headers = $options[CURLOPT_HTTPHEADER] ?? [];
        $explicitPairs = [];
        $filteredHeaders = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Cookie:') === 0) {
                $cookieValue = trim(substr($header, 7));
                foreach (explode(';', $cookieValue) as $pair) {
                    $position = strpos($pair, '=');
                    if ($position === false) continue;
                    $name = trim(substr($pair, 0, $position));
                    if ($name !== '') $explicitPairs[$name] = trim(substr($pair, $position + 1));
                }
                continue;
            }
            $filteredHeaders[] = $header;
        }

        $pairs = [];
        if ($this->cookieJar !== null) {
            foreach ($this->cookieJar->match($url) as $cookie) {
                if (array_key_exists($cookie->getName(), $explicitPairs)) continue;
                $pairs[] = $cookie->getName() . '=' . $cookie->getValue();
            }
        }
        foreach ($explicitPairs as $name => $value) $pairs[] = $name . '=' . $value;

        if ($pairs !== []) $filteredHeaders[] = 'Cookie: ' . implode('; ', $pairs);
        if (isset($options[CURLOPT_HTTPHEADER]) || $pairs !== []) $options[CURLOPT_HTTPHEADER] = $filteredHeaders;
        return $options;
    }

    private function addProxy(array $options): array
    {
        if ($this->proxy !== null) foreach ($this->proxy->getCurlOptions() as $key => $value) $options[$key] = $value;
        return $options;
    }

    private function addCustomCurlOptions(array $options, bool $allowAuth): array
    {
        $protected = $this->getProtectedCurlOptionIds();
        $authNames = [
            'CURLOPT_USERPWD', 'CURLOPT_USERNAME', 'CURLOPT_PASSWORD', 'CURLOPT_HTTPAUTH',
            'CURLOPT_XOAUTH2_BEARER', 'CURLOPT_LOGIN_OPTIONS', 'CURLOPT_NETRC',
            'CURLOPT_NETRC_FILE', 'CURLOPT_AWS_SIGV4', 'CURLOPT_SSLCERT', 'CURLOPT_SSLKEY',
            'CURLOPT_KEYPASSWD', 'CURLOPT_KRBLEVEL',
        ];
        $authOptions = [];
        foreach ($authNames as $name) if (defined($name)) $authOptions[] = constant($name);

        foreach ($this->curlOptions as $option => $value) {
            if (in_array($option, $protected, true)) {
                throw new \InvalidArgumentException('This cURL option is controlled internally by UltraRequest.');
            }
            if (!$allowAuth && in_array($option, $authOptions, true)) continue;
            $options[$option] = $value;
        }
        return $options;
    }

    private function applyCurlOptions(\CurlHandle $handle, array $options): void
    {
        foreach ($options as $option => $value) {
            if (is_int($option) && !curl_setopt($handle, $option, $value)) {
                throw new \RuntimeException('Failed to apply cURL option: ' . $option);
            }
        }
    }

    private function saveResponseCookies(HttpResponse $response, string $url): void
    {
        if ($this->cookieJar === null) return;
        $host = (string)parse_url($url, PHP_URL_HOST);
        foreach ($response->getHeaderValues('Set-Cookie') as $cookieString) {
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
            $this->cookieJar->fromString($cookieString, $host, $path);
        }
    }

    public function download(Downloader $downloader): bool
    {
        if (!$downloader->shouldDownload()) {
            throw new \RuntimeException('File already exists: ' . $downloader->getSavePath());
        }

        $maxAttempts = max(1, $downloader->getMaxRetries() ?? 1);
        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $downloader->resetProgress();
                $this->downloadAttempt($downloader);
                return true;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts && $downloader->getRetryDelay() > 0) sleep($downloader->getRetryDelay());
            }
        }
        throw new \RuntimeException('Download failed after ' . $maxAttempts . ' attempt(s): ' . ($lastError?->getMessage() ?? 'Unknown error'), 0, $lastError);
    }

    private function downloadAttempt(Downloader $downloader): void
    {
        $directory = dirname($downloader->getSavePath());
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create download directory.');
        }
        $request = $downloader->getRequest();
        $initialUrl = $request->getUrl();
        $current = $request;
        $redirects = 0;
        $rangeSegments = 0;
        $maxRedirects = $request->getMaxRedirects() ?? 10;

        while (true) {
            $url = $current->getUrl();
            $allowAuth = $this->sameOrigin($initialUrl, $url);
            $effectiveUrl = $this->addAuthQuery($url, $allowAuth);
            $result = $this->executeDownloadHop($current, $effectiveUrl, $downloader, $allowAuth);
            $response = $result['response'];

            if ($this->isRedirectResponse($response)) {
                $location = $response->getHeader('Location');
                if ($location === null || trim($location) === '') throw new \RuntimeException('Redirect response has no Location header.');
                if (!$current->followsRedirects()) throw new \RuntimeException('Download was redirected but redirects are disabled.');
                if ($redirects >= $maxRedirects) throw new \RuntimeException("Maximum redirects ({$maxRedirects}) exceeded.");
                $nextUrl = $this->resolveUrl($effectiveUrl, trim($location));
                $this->validateRedirectCredentials($effectiveUrl, $nextUrl);
                $current = $this->buildRedirectRequest($current, $nextUrl, $response);
                $redirects++;
                continue;
            }

            $status = $response->getStatusCode();
            if ($status === 416 && $downloader->isCompleteFromRange($response)) {
                $downloader->finishDownload();
                return;
            }
            if (!in_array($status, [200, 206], true)) {
                throw new \RuntimeException('HTTP download failed with status ' . $status . '.');
            }
            if (!$result['written']) throw new \RuntimeException('No response body was written to the download file.');
            if ($status === 200) {
                $contentLength = $response->getHeader('Content-Length');
                if ($contentLength !== null && ctype_digit($contentLength) && (int)filesize($downloader->getPartPath()) !== (int)$contentLength) {
                    throw new \RuntimeException('Downloaded byte count does not match Content-Length.');
                }
            }
            if ($status === 206) {
                $range = $downloader->getContentRange($response);
                if ($range === null || !$downloader->validatePartialResponse($response, $result['requestedOffset'])) {
                    throw new \RuntimeException('The server returned an inconsistent Content-Range response.');
                }
                if ($range['total'] === null) {
                    throw new \RuntimeException('The server did not provide the complete size in Content-Range; refusing to finalize an unverifiable partial file.');
                }
                if ((int)filesize($downloader->getPartPath()) < $range['total']) {
                    if (!$downloader->canResume()) throw new \RuntimeException('A multi-part range response requires resume mode.');
                    $rangeSegments++;
                    if ($rangeSegments > 10000) throw new \RuntimeException('Too many partial download ranges.');
                    $current = $downloader->getRequest();
                    continue;
                }
            }
            $downloader->finishDownload();
            return;
        }
    }

    private function executeDownloadHop(RequestInterface $request, string $url, Downloader $downloader, bool $allowAuth): array
    {
        $handle = curl_init();
        if ($handle === false) throw new \RuntimeException('Failed to initialize cURL.');
        $rawHeaders = '';
        $statusCode = 0;
        $headerValues = [];
        $fileHandle = null;
        $written = false;
        $fileReady = false;
        $writeError = null;
        $requestedOffset = $downloader->canResume() ? $downloader->getExistingSize() : 0;
        $effectiveOffset = $requestedOffset;

        try {
            $options = $this->buildBaseOptions($url, $request);
            $options[CURLOPT_RETURNTRANSFER] = false;
            $options[CURLOPT_HEADER] = false;
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;
            $options = $this->addHeaders($options, $request, $allowAuth);
            $options = $this->addCookies($options, $url, $request);
            $options = $this->addProxy($options);
            $options = $this->addCustomCurlOptions($options, $allowAuth);
            $options[CURLOPT_HEADERFUNCTION] = function ($curl, string $line) use (&$rawHeaders, &$statusCode, &$headerValues, &$fileHandle, &$effectiveOffset, $requestedOffset, &$fileReady, &$writeError, $downloader): int {
                $length = strlen($line);
                $rawHeaders .= $line;
                $trimmed = trim($line);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $matches)) {
                    $statusCode = (int)$matches[1];
                    $headerValues = [];
                } elseif ($trimmed === '') {
                    if ($statusCode === 200) {
                        $effectiveOffset = 0;
                        if (!is_resource($fileHandle)) $fileHandle = fopen($downloader->getPartPath(), 'wb');
                        if (!is_resource($fileHandle)) { $writeError = 'Unable to open destination file for writing.'; return 0; }
                        $fileReady = true;
                    } elseif ($statusCode === 206) {
                        $range = $headerValues['content-range'][count($headerValues['content-range'] ?? []) - 1] ?? '';
                        if (!preg_match('/^bytes\\s+(\\d+)-(\\d+)\\/(\\d+|\\*)$/i', $range, $matches) || (int)$matches[1] !== $requestedOffset) {
                            $writeError = 'Invalid Content-Range response for resumed download.'; return 0;
                        }
                        $effectiveOffset = $requestedOffset;
                        if (!is_resource($fileHandle)) $fileHandle = fopen($downloader->getPartPath(), $requestedOffset > 0 ? 'ab' : 'wb');
                        if (!is_resource($fileHandle)) { $writeError = 'Unable to open destination file for writing.'; return 0; }
                        $fileReady = true;
                    }
                } elseif (($position = strpos($line, ':')) !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $value = trim(substr($line, $position + 1));
                    $headerValues[$name][] = $value;
                }
                return $length;
            };
            $options[CURLOPT_WRITEFUNCTION] = function ($curl, string $chunk) use ($downloader, &$fileHandle, &$statusCode, &$written, &$fileReady, &$writeError): int {
                $length = strlen($chunk);
                if (!in_array($statusCode, [200, 206], true)) return $length;
                if (!is_resource($fileHandle) || !$fileReady) {
                    $writeError = 'Download response headers were not completed before body data.';
                    return 0;
                }

                $chunkSize = $downloader->getChunkSize();
                for ($offset = 0; $offset < $length; $offset += $chunkSize) {
                    $piece = substr($chunk, $offset, $chunkSize);
                    $pieceLength = strlen($piece);
                    $position = 0;
                    while ($position < $pieceLength) {
                        $count = fwrite($fileHandle, substr($piece, $position));
                        if ($count === false || $count === 0) {
                            $writeError = 'Failed while writing downloaded data.';
                            return 0;
                        }
                        $position += $count;
                    }
                }
                $written = $written || $length > 0;
                return $length;
            };
            $options[CURLOPT_BUFFERSIZE] = min(512 * 1024, max(1024, $downloader->getChunkSize()));
            $options[CURLOPT_NOPROGRESS] = false;
            $progressOption = defined('CURLOPT_XFERINFOFUNCTION') ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
            $options[$progressOption] = function ($curl, $downloadTotal, $downloaded, $uploadTotal = 0, $uploaded = 0) use ($downloader, &$effectiveOffset, &$headerValues): int {
                $total = $downloadTotal > 0 ? $downloadTotal + $effectiveOffset : 0;
                $contentRanges = $headerValues['content-range'] ?? [];
                $contentRange = $contentRanges === [] ? null : end($contentRanges);
                if (is_string($contentRange) && preg_match('/^bytes\s+\d+-\d+\/(\d+)$/i', $contentRange, $matches)) {
                    $total = (int)$matches[1];
                }
                $downloader->callProgress($downloaded + $effectiveOffset, $total);
                return 0;
            };
            $this->applyCurlOptions($handle, $options);
            $ok = curl_exec($handle);
            if ($ok === false) {
                $message = $writeError ?? ('cURL error ' . curl_errno($handle) . ': ' . curl_error($handle));
                throw new \RuntimeException($message);
            }
            $info = curl_getinfo($handle);
            $statusCode = (int)($info['http_code'] ?? $statusCode);
            $response = new HttpResponse('', $rawHeaders, $statusCode, $info);
            $this->saveResponseCookies($response, $url);
            if ($statusCode === 206 && !$written) throw new \RuntimeException('The server returned an empty partial response.');
            return ['response' => $response, 'written' => $written || $fileReady, 'requestedOffset' => $requestedOffset];
        } finally {
            if (is_resource($fileHandle)) fclose($fileHandle);
            curl_close($handle);
        }
    }
}
