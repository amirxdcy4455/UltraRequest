<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Client;

/**
 * Describes whether a completed transfer should finish or continue with a new hop.
 *
 * @internal
 */
final class MultiRequestStep
{
    private bool $hasNextJob;

    private mixed $value;

    private function __construct(bool $hasNextJob, mixed $value)
    {
        $this->hasNextJob = $hasNextJob;
        $this->value = $value;
    }

    /** @param array<string, mixed> $job */
    public static function continueWith(array $job): self
    {
        return new self(true, $job);
    }

    public static function complete(mixed $result): self
    {
        return new self(false, $result);
    }

    public function hasNextJob(): bool
    {
        return $this->hasNextJob;
    }

    /** @return array<string, mixed> */
    public function getNextJob(): array
    {
        if (!$this->hasNextJob || !is_array($this->value)) {
            throw new \LogicException('This step does not contain a next job.');
        }

        return $this->value;
    }

    public function getResult(): mixed
    {
        if ($this->hasNextJob) {
            throw new \LogicException('This step contains a next job, not a final result.');
        }

        return $this->value;
    }
}
