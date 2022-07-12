<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

class WorkerState
{
    public float $lastRequestTime;

    public int $requestCount = 0;

    /**
     * @param array<string, int|string> $serverState
     */
    public function __construct(public readonly array $serverState)
    {
    }
}
