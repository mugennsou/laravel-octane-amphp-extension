<?php

namespace Mugennsou\LaravelOctaneExtension\Tests;

use Amp\DeferredFuture;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Exception;
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
        $request->setAttribute('content', 'Hello world!');

        $remoteClient->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 9000));
        $remoteClient->shouldReceive('getProtocolVersion')->andReturn('1.1');

        [$request, $context] = $client->marshalRequest(
            $givenContext = new RequestContext(['amphpRequest' => $request])
        );

        $this->assertInstanceOf(IlluminateRequest::class, $request);
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('Hello world!', $request->getContent());
        $this->assertEquals('127.0.0.1', $request->ip());
        $this->assertEquals('foo/bar', $request->path());
        $this->assertEquals('/foo/bar?name=mugennsou', $request->getRequestUri());
        $this->assertEquals('mugennsou', $request->query('name'));
        $this->assertEquals('blue', $request->cookies->get('color'));
        $this->assertSame($givenContext, $context);
    }

    public function test_respond_method_send_response_to_amphp()
    {
        $client = new AmphpClient();

        $deferred = new DeferredFuture();

        $client->respond(
            new RequestContext(['amphpDeferred' => $deferred]),
            new OctaneResponse(new IlluminateResponse('Hello world!', 200))
        );

        $response = $deferred->getFuture()->await();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('Hello world!', buffer($response->getBody()));
    }

    public function test_respond_method_send_streamed_response_to_amphp()
    {
        $client = new AmphpClient();

        $deferred = new DeferredFuture();

        $client->respond(
            new RequestContext(['amphpDeferred' => $deferred]),
            new OctaneResponse(
                new StreamedResponse(
                    function () {
                        echo 'Hello world!';
                    },
                    200
                )
            )
        );

        $response = $deferred->getFuture()->await();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('Hello world!', buffer($response->getBody()));
    }

    public function test_error_method_sends_error_response_to_amphp()
    {
        $client = new AmphpClient();

        $deferred = new DeferredFuture();

        $client->error(
            new Exception('Something went wrong...'),
            $this->createApplication(),
            IlluminateRequest::create('/'),
            new RequestContext(['amphpDeferred' => $deferred])
        );

        $response = $deferred->getFuture()->await();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals('500 Internal Server Error', $response->getHeader('Status'));
        $this->assertEquals('text/plain', $response->getHeader('Content-Type'));
        $this->assertEquals('Internal server error.', buffer($response->getBody()));
    }

    public function test_respond_method_with_laravel_specific_status_code_sends_response_to_swoole()
    {
        $client = new AmphpClient();

        $deferred = new DeferredFuture();

        $client->respond(
            new RequestContext(['amphpDeferred' => $deferred]),
            new OctaneResponse(new IlluminateResponse('Hello world!', 419, ['Content-Type' => 'text/html']))
        );

        $response = $deferred->getFuture()->await();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(419, $response->getStatus());
        $this->assertEquals('Page Expired', $response->getReason());
        $this->assertEquals('no-cache, private', $response->getHeader('Cache-Control'));
        $this->assertEquals('text/html', $response->getHeader('Content-Type'));
        $this->assertEquals('Hello world!', buffer($response->getBody()));
    }

    public function test_error_method_sends_detailed_error_response_to_amphp_in_debug_mode()
    {
        $client = new AmphpClient();

        $deferred = new DeferredFuture();

        $app = $this->createApplication();
        $app['config']['app.debug'] = true;

        $client->error(
            $e = new Exception('Something went wrong...'),
            $app,
            IlluminateRequest::create('/'),
            new RequestContext(['amphpDeferred' => $deferred])
        );

        $response = $deferred->getFuture()->await();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals('500 Internal Server Error', $response->getHeader('Status'));
        $this->assertEquals('text/plain', $response->getHeader('Content-Type'));
        $this->assertEquals((string)$e, buffer($response->getBody()));
    }

    public function test_can_serve_static_files_if_configured_to_and_file_is_within_public_directory()
    {
        $client = new AmphpClient();

        $request = IlluminateRequest::create('/foo.txt', 'GET');

        $context = new RequestContext(['publicPath' => __DIR__ . '/public']);

        $this->assertTrue($client->canServeRequestAsStaticFile($request, $context));
    }

    public function test_cant_serve_static_files_if_file_is_outside_public_directory()
    {
        $client = new AmphpClient();

        $request = IlluminateRequest::create('/../foo.txt', 'GET');

        $context = new RequestContext(['publicPath' => __DIR__ . '/public/files']);

        $this->assertFalse($client->canServeRequestAsStaticFile($request, $context));
    }

    public function test_cant_serve_static_files_if_file_has_forbidden_extension()
    {
        $client = new AmphpClient();

        $request = IlluminateRequest::create('/foo.php', 'GET');

        $context = new RequestContext(['publicPath' => __DIR__ . '/public/files']);

        $this->assertFalse($client->canServeRequestAsStaticFile($request, $context));
    }

    public function test_can_serve_static_files_through_symlink()
    {
        $client = new AmphpClient();

        $request = IlluminateRequest::create('/symlink/foo.txt', 'GET');

        $context = new RequestContext(['publicPath' => __DIR__ . '/public/files']);

        $this->assertTrue($client->canServeRequestAsStaticFile($request, $context));
    }

    public function test_cant_serve_static_files_through_symlink_using_directory_traversal()
    {
        $client = new AmphpClient();

        $request = IlluminateRequest::create('/symlink/../foo.txt', 'GET');

        $context = new RequestContext(['publicPath' => __DIR__ . '/public/files']);

        $this->assertFalse($client->canServeRequestAsStaticFile($request, $context));
    }

    public function test_static_file_can_be_served()
    {
        $client = new AmphpClient();

        $request = new Request(Mockery::mock(Client::class), 'GET', Http::createFromString('/foo.txt'));
        $illuminateRequest = IlluminateRequest::create('/foo.txt', 'GET');

        $deferred = new DeferredFuture();

        $fileHandler = Mockery::mock(RequestHandler::class);
        $fileHandler->shouldReceive('handleRequest')->with($request)->andReturn(new Response());

        $context = new RequestContext(
            [
                'amphpRequest' => $request,
                'amphpDeferred' => $deferred,
                'fileHandler' => $fileHandler->handleRequest(...),
            ]
        );

        $client->serveStaticFile($illuminateRequest, $context);

        $response = $deferred->getFuture()->await();

        $this->assertInstanceOf(Response::class, $response);
    }
}
