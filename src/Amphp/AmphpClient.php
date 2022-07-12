<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

use Amp\ByteStream\Payload;
use Amp\ByteStream\ReadableStreamChain;
use Amp\DeferredFuture;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Closure;
use DateTime;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Contracts\ServesStaticFiles;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function Amp\Http\Server\StaticContent\removeDotPathSegments;

class AmphpClient implements Client, ServesStaticFiles
{
    protected const STATUS_CODE_REASONS = [
        419 => 'Page Expired',
        418 => 'I\'m a teapot',
        425 => 'Too Early',
        444 => 'Connection Closed Without Response',
        499 => 'Client Closed Request',
        599 => 'Network Connect Timeout Error',
    ];

    /**
     * @param RequestContext $context
     * @return array{IlluminateRequest, RequestContext}
     */
    public function marshalRequest(RequestContext $context): array
    {
        /** @var Request $request */
        $request = $context['amphpRequest'];

        return [
            (new Actions\ConvertAmphpRequestToIlluminateRequest())($request),
            $context,
        ];
    }

    public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
    {
        /** @var DeferredFuture<Response> $amphpDeferred */
        $amphpDeferred = $context['amphpDeferred'];

        $symfonyResponse = $octaneResponse->response;

        $streams = $this->prepareStreams($symfonyResponse, $octaneResponse->outputBuffer);

        $response = new Response(
            $statusCode = $symfonyResponse->getStatusCode(),
            $this->prepareHeaderVariables($symfonyResponse),
            new ReadableStreamChain(...$streams),
        );

        if (!is_null($reason = $this->getReasonFromStatusCode($statusCode))) {
            $response->setStatus($statusCode, $reason);
        }

        $amphpDeferred->complete($response);
    }

    public function error(Throwable $e, Application $app, IlluminateRequest $request, RequestContext $context): void
    {
        /** @var DeferredFuture<Response> $amphpDeferred */
        $amphpDeferred = $context['amphpDeferred'];

        $amphpDeferred->complete(
            new Response(
                Status::INTERNAL_SERVER_ERROR,
                ['Status' => '500 Internal Server Error', 'Content-Type' => 'text/plain'],
                Octane::formatExceptionForClient($e, $app->make('config')->get('app.debug'))
            )
        );
    }

    public function canServeRequestAsStaticFile(IlluminateRequest $request, RequestContext $context): bool
    {
        if (empty($context['publicPath'])) {
            return false;
        }

        $path = removeDotPathSegments($request->getPathInfo());

        if ($path === '/') {
            return false;
        }

        $publicPath = $context['publicPath'];
        $pathToFile = $publicPath . $path;

        if (in_array(pathinfo($pathToFile, PATHINFO_EXTENSION), ['php', 'htaccess', 'config'])) {
            return false;
        }

        if (!is_file($pathToFile)) {
            return false;
        }

        return true;
    }

    public function serveStaticFile(IlluminateRequest $request, RequestContext $context): void
    {
        /**
         * @var Request $amphpRequest
         * @var DeferredFuture<Response> $deferred
         * @var Closure $fileHandler
         */
        ['amphpRequest' => $amphpRequest, 'amphpDeferred' => $deferred, 'fileHandler' => $fileHandler] = $context;

        $deferred->complete($fileHandler($amphpRequest));
    }

    /**
     * @param SymfonyResponse $symfonyResponse
     * @return array<string, array<string>>
     */
    protected function prepareHeaderVariables(SymfonyResponse $symfonyResponse): array
    {
        if (!$symfonyResponse->headers->has('Date')) {
            /** @var DateTime $datetime */
            $datetime = DateTime::createFromFormat('U', (string)time());

            $symfonyResponse->setDate($datetime);
        }

        /** @var array<string, array<string>> $headers */
        $headers = $symfonyResponse->headers->all();

        return $headers;
    }

    /**
     * @param SymfonyResponse $symfonyResponse
     * @param string|null $outputBuffer
     * @return array<int, Payload>
     */
    protected function prepareStreams(SymfonyResponse $symfonyResponse, ?string $outputBuffer = null): array
    {
        $streams = [];

        if ($symfonyResponse instanceof BinaryFileResponse) {
            $streams[] = new Payload($symfonyResponse->getFile()->getContent());

            return $streams;
        }

        if (!is_null($outputBuffer) && strlen($outputBuffer) > 0) {
            $streams[] = new Payload($outputBuffer);
        }

        if ($symfonyResponse instanceof StreamedResponse) {
            ob_start(
                static function (string $data) use (&$streams) {
                    if (strlen($data) > 0) {
                        $streams[] = new Payload($data);
                    }

                    return '';
                },
                1
            );

            $symfonyResponse->sendContent();

            ob_end_clean();

            return $streams;
        }

        if (strlen($content = $symfonyResponse->getContent() ?: '') > 0) {
            $streams[] = new Payload($content);
        }

        return $streams;
    }

    /**
     * Get the HTTP reason clause for non-standard status codes.
     *
     * @param int $code
     * @return string|null
     */
    protected function getReasonFromStatusCode(int $code): ?string
    {
        if (array_key_exists($code, self::STATUS_CODE_REASONS)) {
            return self::STATUS_CODE_REASONS[$code];
        }

        return null;
    }
}
