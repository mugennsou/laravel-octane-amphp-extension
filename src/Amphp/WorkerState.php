<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

use Amp\Http\Server\HttpServer;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Worker;
use Psr\Log\LoggerInterface;

class WorkerState
{
    public Client $client;

    public Worker $worker;

    public float $lastRequestTime;

    public int $requestCount = 0;

    public function __construct(
        public readonly LoggerInterface $logger,
        public readonly HttpServer $server,
        public readonly array $serverState
    ) {
    }
}
