<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\CgiDriver;
use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\ProjectManifest;

/**
 * FastCGI responder for VM scripts or AOT binaries (issue #173).
 */
final class RequestHandler
{
    private string $docroot;

    private ?string $aotBinary;

    public function __construct(string $docroot, ?string $aotBinary = null)
    {
        $resolved = realpath($docroot);
        $this->docroot = false !== $resolved ? $resolved : $docroot;
        $this->aotBinary = null !== $aotBinary && '' !== $aotBinary ? $aotBinary : null;
    }

    /**
     * Handle one or more FastCGI requests on a bidirectional stream (FCGI_KEEP_CONN multiplex).
     *
     * @param resource $stream
     */
    public function handleStream($stream): void
    {
        while (true) {
            $request = Request::readFromStream($stream);
            if (null === $request) {
                break;
            }
            $keepConn = $request->keepConn;
            try {
                $this->dispatchRequest($stream, $request);
            } catch (\Throwable $e) {
                DevServer::logException($e);
                $body = DevServer::formatExceptionBody($e);
                $cgiOut = CgiDriver::formatResponse(500, 'text/plain', $body);
                foreach (Record::encodeStdoutChunks($request->requestId, $cgiOut) as $chunk) {
                    fwrite($stream, $chunk);
                }
                fwrite(
                    $stream,
                    Record::encodeEndRequest($request->requestId, 1, Record::PROTOCOL_STATUS_REQUEST_COMPLETE)
                );
            }
            if (!$keepConn) {
                break;
            }
        }
    }

    private function dispatchRequest($stream, Request $request): void
    {
        Environment::apply($request->params);
        Environment::applyRequestBody($request->params, $request->stdinBody);

        if (null !== $this->aotBinary) {
            [$status, $contentType, $body, $extraHeaders] = CgiAotDriver::runCapture(
                $this->aotBinary,
                ProjectManifest::resolveProjectDir($this->docroot)
            );
        } else {
            $script = $this->resolveScript($request->params);
            [$status, $contentType, $body, $extraHeaders] = CgiDriver::runVmScript($script);
        }

        $cgiOut = CgiDriver::formatResponse($status, $contentType, $body, $extraHeaders);
        foreach (Record::encodeStdoutChunks($request->requestId, $cgiOut) as $chunk) {
            fwrite($stream, $chunk);
        }
        fwrite(
            $stream,
            Record::encodeEndRequest($request->requestId, 0, Record::PROTOCOL_STATUS_REQUEST_COMPLETE)
        );
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
