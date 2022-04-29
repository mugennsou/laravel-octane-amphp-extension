<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp\Handlers;

use Amp\Http\Server\HttpServer;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Worker;
use Mugennsou\LaravelOctaneExtension\Amphp\AmphpClient;
use Mugennsou\LaravelOctaneExtension\Amphp\WorkerState;
use Symfony\Component\HttpFoundation\Response;

class OnStart
{
    public function __construct(protected WorkerState $workerState, protected string $basePath)
    {
    }

    public function __invoke(HttpServer $server): void
    {
        $this->workerState->worker = $this->bootWorker();

        $this->streamRequestsToConsole();
    }

    protected function bootWorker(): Worker
    {
        $worker = new Worker(
            new ApplicationFactory($this->basePath),
            $this->workerState->client = new AmphpClient()
        );

        $worker->boot(
            [
                HttpServer::class => $this->workerState->server,
                WorkerState::class => $this->workerState,
            ]
        );

        return $worker;
    }

    protected function streamRequestsToConsole()
    {
        $this->workerState->worker->onRequestHandled(
            function (IlluminateRequest $request, Response $response, Application $sandbox): void {
                if (!$sandbox->environment('local', 'testing')) {
                    return;
                }

                $this->workerState->logger->info(
                    'request',
                    [
                        'type' => 'request',
                        'method' => $request->getMethod(),
                        'url' => $request->fullUrl(),
                        'memory' => memory_get_usage(),
                        'statusCode' => $response->getStatusCode(),
                        'duration' => (microtime(true) - $this->workerState->lastRequestTime) * 1000,
                    ]
                );
            }
        );
    }
}
