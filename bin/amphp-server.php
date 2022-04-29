<?php

declare(strict_types=1);

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Socket\InternetAddress;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Mugennsou\LaravelOctaneExtension\Amphp\Handlers\OnStart;
use Mugennsou\LaravelOctaneExtension\Amphp\Handlers\OnStop;
use Mugennsou\LaravelOctaneExtension\Amphp\Logger\StreamHandler;
use Mugennsou\LaravelOctaneExtension\Amphp\RequestHandler;
use Mugennsou\LaravelOctaneExtension\Amphp\WorkerState;

use function Amp\trapSignal;

$basePath = require $_SERVER['APP_BASE_PATH'] . '/vendor/laravel/octane/bin/bootstrap.php';

if (!is_string($basePath)) {
    fwrite(STDERR, 'Cannot find application base path.' . PHP_EOL);

    exit(11);
}

$loggerHandler = (new StreamHandler(ByteStream\getStdout()))->pushProcessor(new PsrLogMessageProcessor());
$logger = (new Logger('server'))->pushHandler($loggerHandler);

$server = new SocketHttpServer($logger);
$serverState = json_decode(file_get_contents($_SERVER['STATE_FILE']), true)['state'];

$workerState = new WorkerState($logger, $server, $serverState);

$errorHandler = new DefaultErrorHandler();
$fileHandler = new DocumentRoot($server, $errorHandler, $serverState['publicPath']);
$requestHandler = new RequestHandler($errorHandler, $fileHandler, $workerState);

$server->onStart((new OnStart($workerState, $basePath))(...));
$server->onStop((new OnStop($workerState))(...));

try {
    $server->expose(new InternetAddress($serverState['host'], $serverState['port']));

    $server->start($requestHandler, $errorHandler);

    $signal = trapSignal([SIGUSR1, SIGINT, SIGTERM]);

    $logger->info(sprintf("Received signal %d, stopping HTTP server", $signal));

    $server->stop();

    exit($signal);
} catch (Throwable $e) {
    $logger->error($e->getMessage());
}
