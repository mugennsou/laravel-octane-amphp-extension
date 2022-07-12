<?php

declare(strict_types=1);

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Socket\InternetAddress;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Worker;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Mugennsou\LaravelOctaneExtension\Amphp\AmphpClient;
use Mugennsou\LaravelOctaneExtension\Amphp\Logger\StreamHandler;
use Mugennsou\LaravelOctaneExtension\Amphp\RequestHandler;
use Mugennsou\LaravelOctaneExtension\Amphp\WorkerState;

use Symfony\Component\HttpFoundation\Response;

use function Amp\trapSignal;

$basePath = require $_SERVER['APP_BASE_PATH'] . '/vendor/laravel/octane/bin/bootstrap.php';

if (!is_string($basePath)) {
    fwrite(STDERR, 'Cannot find application base path.' . PHP_EOL);

    exit(11);
}

$serverState = json_decode(file_get_contents($_SERVER['STATE_FILE']), true)['state'];
$client = new AmphpClient();
$worker = tap(new Worker(new ApplicationFactory($basePath), $client))->boot();
$workerState = new WorkerState($serverState);

$loggerHandler = (new StreamHandler(ByteStream\getStdout()))->pushProcessor(new PsrLogMessageProcessor());
$logger = (new Logger('server'))->pushHandler($loggerHandler);

$server = new SocketHttpServer($logger);
$errorHandler = new DefaultErrorHandler();
$fileHandler = new DocumentRoot($server, $errorHandler, $serverState['publicPath']);
$requestHandler = new RequestHandler($errorHandler, $fileHandler, $worker, $client, $workerState);

$worker->onRequestHandled(
    function (IlluminateRequest $request, Response $response, Application $sandbox) use ($logger, $workerState): void {
        if (!$sandbox->environment('local', 'testing')) {
            return;
        }

        $logger->info(
            'request',
            [
                'type' => 'request',
                'method' => $request->getMethod(),
                'url' => $request->fullUrl(),
                'memory' => memory_get_usage(),
                'statusCode' => $response->getStatusCode(),
                'duration' => (microtime(true) - $workerState->lastRequestTime) * 1000,
            ]
        );
    }
);

try {
    $server->expose(new InternetAddress($serverState['host'], $serverState['port']));

    $server->start($requestHandler, $errorHandler);

    $signal = trapSignal([SIGUSR1, SIGINT, SIGTERM]);

    $logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

    $server->stop();

    $worker->terminate();

    exit($signal);
} catch (Throwable $e) {
    $logger->error($e->getMessage());
}
