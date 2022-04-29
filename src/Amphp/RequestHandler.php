<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\StreamException;
use Amp\DeferredFuture;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\FormParser\BufferingParser;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\FormParser\ParseException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler as RequestHandlerInterface;
use Amp\Http\Server\Response;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Laravel\Octane\RequestContext;
use Throwable;

use function Amp\Http\Server\FormParser\parseContentBoundary;

class RequestHandler implements RequestHandlerInterface
{
    private readonly BufferingParser $parser;

    public function __construct(
        protected readonly ErrorHandler $errorHandler,
        protected readonly DocumentRoot $fileHandler,
        protected readonly WorkerState $workerState,
    ) {
        $this->parser = new BufferingParser();
    }

    public function handleRequest(Request $request): Response
    {
        $this->workerState->lastRequestTime = microtime(true);
        $this->workerState->requestCount++;

        try {
            $this->parseRequestBody($request);
        } catch (Throwable) {
            return $this->errorHandler->handleError(Status::BAD_REQUEST, request: $request);
        }

        $deferred = new DeferredFuture();

        [$illuminateRequest, $context] = $this->workerState->client->marshalRequest(
            new RequestContext(
                [
                    'amphpRequest' => $request,
                    'amphpDeferred' => $deferred,
                    'fileHandler' => $this->fileHandler->handleRequest(...),
                    'publicPath' => $this->workerState->serverState['publicPath'],
                    'octaneConfig' => $this->workerState->serverState['octaneConfig'],
                ]
            )
        );

        $this->workerState->worker->handle($illuminateRequest, $context);

        return $deferred->getFuture()->await();
    }

    /**
     * @param Request $request
     * @return void
     *
     * @throws ParseException
     * @throws BufferException
     * @throws StreamException
     */
    protected function parseRequestBody(Request $request): void
    {
        $boundary = parseContentBoundary($request->getHeader('content-type') ?? '');

        $body = $request->getBody()->buffer();

        switch ($boundary) {
            case '':
                $request->setAttribute('content', $body);
                $request->setAttribute(Form::class, $this->parser->parseUrlEncodedBody($body));
                break;
            case null:
                $request->setAttribute('content', $body);
                $request->setAttribute(Form::class, new Form([]));
                break;
            default:
                $request->setAttribute('content', null);
                $request->setAttribute(Form::class, $this->parser->parseMultipartBody($body, $boundary));
                break;
        }
    }
}
