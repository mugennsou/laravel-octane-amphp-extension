<?php

namespace Mugennsou\LaravelOctaneExtension\Tests;

use Amp\Deferred;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\SocketAddress;
use Exception;
use Generator;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response as IlluminateResponse;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use League\Uri\Http;
use Mockery;
use Mugennsou\LaravelOctaneExtension\Amphp\AmphpClient;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Amp\ByteStream\buffer;

class AmphpClientTest extends TestCase
{
    public function test_marshal_request_method_marshals_proper_illuminate_request()
    {
        $client = new AmphpClient();

        $request = new Request(
            $remoteClient = Mockery::mock(Client::class),
            'POST',
            Http::createFromString('http://localhost/foo/bar?name=mugennsou'),
        );
        $request->setCookie(new RequestCookie('color', 'blue'));
        $request->setAttribute(Form::class, new Form([]));
        $request->setAttribute('content', 'Hello World');

        $remoteClient->shouldReceive('getRemoteAddress')->andReturn(SocketAddress::fromSocketName('127.0.0.1:9000'));
        $remoteClient->shouldReceive('getProtocolVersion')->andReturn('1.1');

        [$request, $context] = $client->marshalRequest(
            $givenContext = new RequestContext(['amphpRequest' => $request])
        );

        $this->assertInstanceOf(IlluminateRequest::class, $request);
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('Hello World', $request->getContent());
        $this->assertEquals('127.0.0.1', $request->ip());
        $this->assertEquals('foo/bar', $request->path());
        $this->assertEquals('/foo/bar?name=mugennsou', $request->getRequestUri());
        $this->assertEquals('mugennsou', $request->query('name'));
        $this->assertEquals('blue', $request->cookies->get('color'));
        $this->assertSame($givenContext, $context);
    }

    public function test_respond_method_send_response_to_amphp(): Generator
    {
        $client = new AmphpClient();

        $request = new Request(Mockery::mock(Client::class), 'GET', Http::createFromString('/home?name=Taylor'));
        $request->setAttribute(Form::class, new Form([]));

        $deferred = new Deferred();

        $client->respond(
            new RequestContext(['amphpRequest' => $request, 'amphpDeferred' => $deferred]),
            new OctaneResponse(new IlluminateResponse('Hello World', 200))
        );

        $response = yield $deferred->promise();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('Hello World', yield buffer($response->getBody()));
    }

    public function test_respond_method_send_streamed_response_to_amphp(): Generator
    {
        $client = new AmphpClient();

        $request = new Request(Mockery::mock(Client::class), 'GET', Http::createFromString('/home?name=Taylor'));
        $request->setAttribute(Form::class, new Form([]));

        $deferred = new Deferred();

        $client->respond(
            new RequestContext(['amphpRequest' => $request, 'amphpDeferred' => $deferred]),
            new OctaneResponse(new StreamedResponse(function () { echo 'Hello World'; }, 200))
        );

        $response = yield $deferred->promise();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('Hello World', yield buffer($response->getBody()));
    }

    public function test_error_method_sends_error_response_to_amphp(): Generator
    {
        $client = new AmphpClient();

        $deferred = new Deferred();

        $client->error(
            new Exception('Something went wrong...'),
            $this->createApplication(),
            IlluminateRequest::create('/'),
            new RequestContext(['amphpDeferred' => $deferred])
        );

        $response = yield $deferred->promise();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals('500 Internal Server Error', $response->getHeader('Status'));
        $this->assertEquals('text/plain', $response->getHeader('Content-Type'));
        $this->assertEquals('Internal server error.', yield buffer($response->getBody()));
    }

    public function test_error_method_sends_detailed_error_response_to_amphp_in_debug_mode(): Generator
    {
        $client = new AmphpClient();

        $deferred = new Deferred();

        $app = $this->createApplication();
        $app['config']['app.debug'] = true;

        $client->error(
            $e = new Exception('Something went wrong...'),
            $app,
            IlluminateRequest::create('/'),
            new RequestContext(['amphpDeferred' => $deferred])
        );

        $response = yield $deferred->promise();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals('500 Internal Server Error', $response->getHeader('Status'));
        $this->assertEquals('text/plain', $response->getHeader('Content-Type'));
        $this->assertEquals((string)$e, yield buffer($response->getBody()));
    }
}
