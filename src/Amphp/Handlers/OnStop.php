<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp\Handlers;

use Amp\Http\Server\HttpServer;
use Mugennsou\LaravelOctaneExtension\Amphp\WorkerState;

class OnStop
{
    public function __construct(protected WorkerState $workerState)
    {
    }

    public function __invoke(HttpServer $server): void
    {
        $this->workerState->worker->terminate();
    }
}
