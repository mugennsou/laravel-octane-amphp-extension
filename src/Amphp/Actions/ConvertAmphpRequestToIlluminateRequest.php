<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp\Actions;

use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\FormParser\File;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request;
use Illuminate\Http\Request as IlluminateRequest;
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
        $form = $request->getAttribute(Form::class);

        if (str_contains($request->getHeader('content-type') ?? '', 'multipart/form-data')) {
            $content = null;
            $parameters = $this->prepareRequestParameterVariables($this->buildQuery($form->getValues()));
        } else {
            $content = $request->getAttribute('content');
            $parameters = $this->prepareRequestParameterVariables($content);
        }

        $cookies = $this->prepareCookieVariables(...$request->getCookies());

        $files = $this->prepareFileVariables($form->getFiles());

        $server = $this->prepareServerVariables(
            [
                'REMOTE_ADDR' => $request->getClient()->getRemoteAddress()->getHost(),
                'REMOTE_PORT' => $request->getClient()->getRemoteAddress()->getPort(),
                'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
            ],
            $request->getHeaders()
        );

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
     * Build http query from Amphp Form's fields.
     *
     * @param array $fields
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
     * Parse the "parameters" variables form raw content.
     *
     * @param string $content
     * @return array
     */
    protected function prepareRequestParameterVariables(string $content): array
    {
        parse_str($content, $results);

        return $results;
    }

    /**
     * Parse the "cookies" variables.
     *
     * @param RequestCookie ...$cookies
     * @return array
     */
    protected function prepareCookieVariables(RequestCookie ...$cookies): array
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
     * @param array $files
     * @return array
     */
    protected function prepareFileVariables(array $files): array
    {
        $queries = [];
        $uploadFiles = [];

        foreach ($files as $field => $values) {
            foreach ($values as $file) {
                $fileHash = spl_object_hash($file);
                $queries[] = http_build_query([$field => $fileHash]);

                $uploadFiles[$fileHash] = $this->convertUploadFile($file);
            }
        }

        parse_str(implode('&', $queries), $results);

        return $this->replaceUploadFile($results, $uploadFiles);
    }

    /**
     * Parse the "server" variables and headers into a single array of $_SERVER variables.
     *
     * @param array $server
     * @param array $headers
     * @return array
     */
    protected function prepareServerVariables(array $server, array $headers): array
    {
        return array_merge($server, $this->formatHttpHeadersIntoServerVariables($headers));
    }

    /**
     * Convert Amphp File object into Symfony UploadedFile.
     *
     * @param File $file
     * @return UploadedFile
     */
    protected function convertUploadFile(File $file): UploadedFile
    {
        $tempDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'amphp.upload.');

        file_put_contents($tempFile, $file->getContents());

        return new UploadedFile($tempFile, $file->getName(), $file->getMimeType());
    }

    /**
     * Replace results object hash symbol with upload files.
     *
     * @param array $results
     * @param array $uploadFiles
     * @return array
     */
    protected function replaceUploadFile(array $results, array $uploadFiles): array
    {
        foreach ($results as &$result) {
            if (is_array($result)) {
                $result = $this->replaceUploadFile($result, $uploadFiles);

                continue;
            }

            if (is_string($result)) {
                $result = $uploadFiles[$result];
            }
        }

        return $results;
    }

    /**
     * Format the given HTTP headers into properly formatted $_SERVER variables.
     *
     * @param array $headers
     * @return array
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
