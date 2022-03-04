<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStreamChain;
use Amp\Deferred;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use DateTime;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AmphpClient implements Client
{
    protected const STATUS_CODE_REASONS = [
        419 => 'Page Expired',
        418 => 'I\'m a teapot',
        425 => 'Too Early',
        444 => 'Connection Closed Without Response',
        499 => 'Client Closed Request',
        599 => 'Network Connect Timeout Error',
    ];

    public function marshalRequest(RequestContext $context): array
    {
        return [
            (new Actions\ConvertAmphpRequestToIlluminateRequest())($context['amphpRequest']),
            $context,
        ];
    }

    public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
    {
        /** @var Deferred $amphpDeferred */
        $amphpDeferred = $context['amphpDeferred'];

        $symfonyResponse = $octaneResponse->response;

        $streams = $this->prepareStreams($symfonyResponse, $octaneResponse->outputBuffer);

        $response = new Response(
            $statusCode = $symfonyResponse->getStatusCode(),
            $this->prepareHeaderVariables($symfonyResponse),
            new InputStreamChain(...$streams),
        );

        if (!is_null($reason = $this->getReasonFromStatusCode($statusCode))) {
            $response->setStatus($statusCode, $reason);
        }

        $amphpDeferred->resolve($response);
    }

    public function error(Throwable $e, Application $app, IlluminateRequest $request, RequestContext $context): void
    {
        /** @var Deferred $amphpDeferred */
        $amphpDeferred = $context['amphpDeferred'];

        $amphpDeferred->resolve(
            new Response(
                Status::INTERNAL_SERVER_ERROR,
                ['Status' => '500 Internal Server Error', 'Content-Type' => 'text/plain'],
                Octane::formatExceptionForClient($e, $app->make('config')->get('app.debug'))
            )
        );
    }

    protected function prepareHeaderVariables(SymfonyResponse $symfonyResponse): array
    {
        if (!$symfonyResponse->headers->has('Date')) {
            $symfonyResponse->setDate(DateTime::createFromFormat('U', time()));
        }

        return $symfonyResponse->headers->all();
    }

    protected function prepareStreams(SymfonyResponse $symfonyResponse, ?string $outputBuffer = null): array
    {
        $streams = [];

        if ($symfonyResponse instanceof BinaryFileResponse) {
            $streams[] = new InMemoryStream($symfonyResponse->getFile()->getContent());

            return $streams;
        }

        if (!is_null($outputBuffer) && strlen($outputBuffer) > 0) {
            $streams[] = new InMemoryStream($outputBuffer);
        }

        if ($symfonyResponse instanceof StreamedResponse) {
            ob_start(
                static function (string $data) use (&$streams) {
                    if (strlen($data) > 0) {
                        $streams[] = new InMemoryStream($data);
                    }

                    return '';
                },
                1
            );

            $symfonyResponse->sendContent();

            ob_end_clean();

            return $streams;
        }

        if (strlen($content = $symfonyResponse->getContent()) > 0) {
            $streams[] = new InMemoryStream($content);
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
