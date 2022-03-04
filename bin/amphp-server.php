<?php

declare(strict_types=1);

use Amp\Cluster\Cluster;
use Amp\Deferred;
use Amp\Http\Server\FormParser\ParsingMiddleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\HttpServer;
use Amp\Loop;
use Amp\Promise;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Monolog\Logger;
use Mugennsou\LaravelOctaneExtension\Amphp\AmphpClient;

use function Amp\Http\Server\Middleware\stack;

ini_set('display_errors', 'stderr');

$_ENV['APP_RUNNING_IN_CONSOLE'] = false;

$basePath = $_SERVER['APP_BASE_PATH'] ?? $_ENV['APP_BASE_PATH'] ?? null;

if (!is_string($basePath)) {
    fwrite(STDERR, 'Cannot find application base path.' . PHP_EOL);

    exit(11);
}

$serverState = json_decode(file_get_contents($serverStateFile = $_SERVER['STATE_FILE']), true)['state'];

Loop::run(
    static function () use ($basePath, $serverState): Generator {
        $sockets = yield [Cluster::listen(sprintf('%s:%s', $serverState['host'], $serverState['port']))];

        $client = new AmphpClient();
        $worker = tap(new Worker(new ApplicationFactory($basePath), $client))->boot();

        $handler = stack(
            new CallableRequestHandler(
                static function (Request $request) use ($worker, $client, $serverState): Generator {
                    $deferred = new Deferred();

                    $request->setAttribute('content', yield $request->getBody()->buffer());

                    [$illuminateRequest, $context] = $client->marshalRequest(
                        new RequestContext(
                            [
                                'amphpRequest' => $request,
                                'amphpDeferred' => $deferred,
                            ]
                        )
                    );

                    $worker->handle($illuminateRequest, $context);

                    return $deferred->promise();
                }
            ),
            new ParsingMiddleware()
        );

        $logger = tap(new Logger('worker-' . Cluster::getId()))->pushHandler(Cluster::createLogHandler());

        $server = new HttpServer($sockets, $handler, $logger);

        Cluster::onTerminate(fn(): Promise => $server->stop());

        yield $server->start();
    }
);
