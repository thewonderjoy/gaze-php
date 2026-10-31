<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Support;

/**
 * Process-wide cap on sent entries: Gaze::rateLimiter()->max(100)->perSecond(10).
 *
 * When a cap is reached, one "Rate limit has been reached..." entry is sent and further
 * entries are dropped until clear(). Unlike spatie/ray, it exists before the first call
 * and its notified flag is initialised (01 §11 #5).
 */
final class RateLimiter
{
    private ?int $max = null;

    private ?int $perSecond = null;

    private int $count = 0;

    /** @var array<int, int> hits per unix second (last two seconds kept) */
    private array $second = [];

    private bool $notified = false;

    public function max(?int $maxCalls): self
    {
        $this->max = $maxCalls !== null && $maxCalls > 0 ? $maxCalls : null;

        return $this;
    }

    public function perSecond(?int $callsPerSecond): self
    {
        $this->perSecond = $callsPerSecond !== null && $callsPerSecond > 0 ? $callsPerSecond : null;

        return $this;
    }

    public function clear(): self
    {
        $this->max = $this->perSecond = null;
        $this->count = 0;
        $this->second = [];
        $this->notified = false;

        return $this;
    }

    public function isMaxReached(): bool
    {
        return $this->max !== null && $this->count >= $this->max;
    }

    public function isMaxPerSecondReached(): bool
    {
        return $this->perSecond !== null && ($this->second[time()] ?? 0) >= $this->perSecond;
    }

    public function reached(): bool
    {
        $reached = $this->isMaxReached() || $this->isMaxPerSecondReached();
        if (!$reached) {
            $this->notified = false;
        }

        return $reached;
    }

    public function hit(): void
    {
        ++$this->count;
        if ($this->perSecond !== null) {
            $now = time();
            $this->second = [$now => ($this->second[$now] ?? 0) + 1];
        }
    }

    public function isNotified(): bool
    {
        return $this->notified;
    }

    public function notify(): void
    {
        $this->notified = true;
    }

    public function count(): int
    {
        return $this->count;
    }
}
