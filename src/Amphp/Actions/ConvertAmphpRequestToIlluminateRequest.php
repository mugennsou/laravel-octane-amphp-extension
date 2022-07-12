<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp\Actions;

use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\FormParser\BufferedFile;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class ConvertAmphpRequestToIlluminateRequest
{
    /**
     * Convert the given Amphp request into an Illuminate request.
     *
     * @param Request $request
     * @return IlluminateRequest
     */
    public function __invoke(Request $request): IlluminateRequest
    {
        /** @var Form $form */
        $form = $request->hasAttribute(Form::class) ? $request->getAttribute(Form::class) : new Form([]);

        $parameters = $this->prepareRequestParameterVariables($this->buildQuery($form->getValues()));

        $cookies = $this->prepareCookieVariables($request->getCookies());

        $files = $this->prepareFileVariables($form->getFiles());

        /** @var InternetAddress $remoteAddress */
        $remoteAddress = $request->getClient()->getRemoteAddress();

        $server = $this->prepareServerVariables(
            [
                'REMOTE_ADDR' => $remoteAddress->getAddress(),
                'REMOTE_PORT' => $remoteAddress->getPort(),
                'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
            ],
            $request->getHeaders()
        );

        /** @var string|null $content */
        $content = $request->hasAttribute('content') ? $request->getAttribute('content') : null;

        $symfonyRequest = SymfonyRequest::create(
            $request->getUri()->__toString(),
            $request->getMethod(),
            $parameters,
            $cookies,
            $files,
            $server,
            $content
        );

        return IlluminateRequest::createFromBase($symfonyRequest);
    }

    /**
     * Parse the "parameters" variables form raw content.
     *
     * @param string $content
     * @return array<string, string>
     */
    protected function prepareRequestParameterVariables(string $content): array
    {
        parse_str($content, $results);

        return $results;
    }

    /**
     * Parse the "cookies" variables.
     *
     * @param array<string, RequestCookie> $cookies
     * @return array<string, string>
     */
    protected function prepareCookieVariables(array $cookies): array
    {
        return array_reduce(
            $cookies,
            function (array $cookies, RequestCookie $cookie): array {
                return $cookies + [$cookie->getName() => urldecode($cookie->getValue())];
            },
            []
        );
    }

    /**
     * Parse the "files" variables.
     *
     * @param array<string, array<BufferedFile>> $files
     * @return array<string, UploadedFile|mixed>
     */
    protected function prepareFileVariables(array $files): array
    {
        $queries = [];
        $uploadFiles = [];

        foreach ($files as $field => $values) {
            /** @var BufferedFile $file */
            foreach ($values as $file) {
                $fileHash = spl_object_hash($file);
                $queries[] = http_build_query([$field => $fileHash]);

                $uploadFiles[$fileHash] = $this->convertUploadFile($file);
            }
        }

        parse_str(implode('&', $queries), $parsed);

        return $this->replaceUploadFile($parsed, $uploadFiles);
    }

    /**
     * Parse the "server" variables and headers into a single array of $_SERVER variables.
     *
     * @param array<string, int|string> $server
     * @param array<string, array<string>> $headers
     * @return array<string, int|string>
     */
    protected function prepareServerVariables(array $server, array $headers): array
    {
        return array_merge($server, $this->formatHttpHeadersIntoServerVariables($headers));
    }

    /**
     * Build http query from Amphp Form's fields.
     *
     * @param array<string, array<string>> $fields
     * @return string
     */
    protected function buildQuery(array $fields): string
    {
        $queries = [];

        foreach ($fields as $field => $values) {
            foreach ($values as $value) {
                $queries[] = http_build_query([$field => $value]);
            }
        }

        return implode('&', $queries);
    }

    /**
     * Convert Amphp File object into Symfony UploadedFile.
     *
     * @param BufferedFile $file
     * @return UploadedFile|null
     */
    protected function convertUploadFile(BufferedFile $file): ?UploadedFile
    {
        if ($file->getName() === '' && $file->getContents() === '') {
            return null;
        }

        $tempFile = tempnam(ini_get('upload_tmp_dir') ?: sys_get_temp_dir(), 'amphp.upload.')
            ?: '/tmp/amphp.upload.' . Str::random();

        file_put_contents($tempFile, $file->getContents());

        return new UploadedFile($tempFile, $file->getName(), $file->getMimeType());
    }

    /**
     * Replace results object hash symbol with upload files.
     *
     * @param array<string, string|mixed> $parsed
     * @param array<UploadedFile|null> $uploadFiles
     * @return array<string, UploadedFile|mixed>
     */
    protected function replaceUploadFile(array $parsed, array $uploadFiles): array
    {
        $results = [];

        foreach ($parsed as $key => $result) {
            if (is_array($result)) {
                $results[$key] = $this->replaceUploadFile($result, $uploadFiles);

                continue;
            }

            if (is_string($result) && isset($uploadFiles[$result])) {
                $results[$key] = $uploadFiles[$result];
            }
        }

        return $results;
    }

    /**
     * Format the given HTTP headers into properly formatted $_SERVER variables.
     *
     * @param array<string, array<string>> $headers
     * @return array<string, string>
     */
    protected function formatHttpHeadersIntoServerVariables(array $headers): array
    {
        $results = [];

        foreach ($headers as $key => $value) {
            $key = strtoupper(str_replace('-', '_', $key));

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $results[$key] = implode(';', $value);
            }

            $results['HTTP_' . $key] = implode(';', $value);
        }

        return $results;
    }
}
