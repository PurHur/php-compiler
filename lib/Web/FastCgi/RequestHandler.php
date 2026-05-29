<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

use PHPCompiler\Web\CgiDriver;
use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\ProjectManifest;

/**
 * Single-request FastCGI responder (VM scripts; issue #173 slice 2).
 */
final class RequestHandler
{
    private string $docroot;

    public function __construct(string $docroot)
    {
        $resolved = realpath($docroot);
        $this->docroot = false !== $resolved ? $resolved : $docroot;
    }

    /**
     * Handle one FastCGI request on a bidirectional stream (accept socket or stdin).
     *
     * @param resource $stream
     */
    public function handleStream($stream): void
    {
        try {
            $request = Request::readFromStream($stream);
            Environment::apply($request->params);
            Environment::applyRequestBody($request->params, $request->stdinBody);
            $script = $this->resolveScript($request->params);
            [$status, $contentType, $body, $extraHeaders] = CgiDriver::runVmScript($script);
            $cgiOut = CgiDriver::formatResponse($status, $contentType, $body, $extraHeaders);
            foreach (Record::encodeStdoutChunks($request->requestId, $cgiOut) as $chunk) {
                fwrite($stream, $chunk);
            }
            fwrite(
                $stream,
                Record::encodeEndRequest($request->requestId, 0, Record::PROTOCOL_STATUS_REQUEST_COMPLETE)
            );
        } catch (\Throwable $e) {
            DevServer::logException($e);
            $body = DevServer::formatExceptionBody($e);
            $cgiOut = CgiDriver::formatResponse(500, 'text/plain', $body);
            $requestId = 1;
            foreach (Record::encodeStdoutChunks($requestId, $cgiOut) as $chunk) {
                fwrite($stream, $chunk);
            }
            fwrite(
                $stream,
                Record::encodeEndRequest($requestId, 1, Record::PROTOCOL_STATUS_REQUEST_COMPLETE)
            );
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function resolveScript(array $params): string
    {
        $scriptFilename = $params['SCRIPT_FILENAME'] ?? '';
        if ('' === $scriptFilename) {
            $scriptName = $params['SCRIPT_NAME'] ?? '/index.php';
            $docRoot = $params['DOCUMENT_ROOT'] ?? $this->docroot;
            $scriptFilename = rtrim($docRoot, '/').'/'.ltrim($scriptName, '/');
        }
        $real = realpath($scriptFilename);
        if (false === $real || !is_file($real)) {
            throw new \RuntimeException('Script not found: '.$scriptFilename);
        }
        $docroot = realpath($params['DOCUMENT_ROOT'] ?? $this->docroot);
        if (false !== $docroot && !str_starts_with($real, $docroot)) {
            throw new \RuntimeException('Script outside document root: '.$real);
        }
        $public = ProjectManifest::resolvePublicDir($docroot ?? $this->docroot);
        $publicReal = realpath($public);
        if (false !== $publicReal && !str_starts_with($real, $publicReal)) {
            throw new \RuntimeException('Script outside public dir: '.$real);
        }

        return $real;
    }
}
