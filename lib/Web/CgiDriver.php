<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\Runtime;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ScriptExit;

/**
 * CGI/1.1 VM driver: read env + stdin, run a script, emit Status + headers + body (issue #50).
 */
final class CgiDriver
{
    /**
     * Read POST body from stdin when CONTENT_LENGTH is set (real CGI from nginx/apache).
     */
    public static function ingestStdinRequestBody(): void
    {
        $raw = getenv('CONTENT_LENGTH');
        if (false === $raw || '' === $raw) {
            return;
        }
        $len = (int) $raw;
        if ($len <= 0) {
            return;
        }
        if ($len > DevServer::maxRequestBody()) {
            fwrite(STDOUT, self::formatResponse(413, 'text/plain', "Payload Too Large\n"));
            exit(0);
        }
        $body = '';
        while (strlen($body) < $len && !feof(STDIN)) {
            $chunk = fread(STDIN, $len - strlen($body));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $body .= $chunk;
        }
        if (strlen($body) > $len) {
            $body = substr($body, 0, $len);
        }
        putenv('REQUEST_BODY='.$body);
        $_ENV['REQUEST_BODY'] = $body;
        $_SERVER['REQUEST_BODY'] = $body;
        if ('' !== $body && 'POST' !== strtoupper((string) getenv('REQUEST_METHOD'))) {
            putenv('REQUEST_METHOD=POST');
            $_ENV['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_METHOD'] = 'POST';
        }
    }

    /**
     * Execute a PHP script under the current CGI environment and return response parts.
     *
     * @return array{0: int, 1: string, 2: string, 3: list<string>}
     */
    public static function runVmScript(string $script): array
    {
        ResponseContext::reset();
        VmSession::reset();
        OutputBuffer::reset();
        $code = file_get_contents($script);
        if (false === $code) {
            throw new \RuntimeException('Could not read script: '.$script);
        }

        $queryString = getenv('QUERY_STRING');
        $queryString = false === $queryString ? '' : $queryString;
        $postBody = Superglobals::readRequestBody();
        $scriptFilename = realpath($script);
        if (false === $scriptFilename) {
            $scriptFilename = $script;
        }

        ob_start();
        try {
            $runtime = new Runtime();
            Superglobals::populateFromEnvironment(
                $runtime->vmContext,
                $queryString,
                $postBody,
                $scriptFilename
            );
            [$bootProjectDir, $bootManifest] = ProjectBootstrap::resolveFromScript($script);
            ProjectBootstrap::prepare($runtime, $bootProjectDir, $bootManifest);
            $block = $runtime->parseAndCompile($code, $script);
            $runtime->run($block);
            $output = ob_get_clean();
        } catch (ScriptExit $e) {
            ob_end_clean();
            exit($e->status);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return self::collectResponse($output !== false ? $output : '');
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: list<string>}
     */
    private static function collectResponse(string $output): array
    {
        $responseHeaders = ResponseContext::listHeaders();
        if ([] === $responseHeaders && \function_exists('headers_list')) {
            $responseHeaders = \headers_list();
        }
        if (\function_exists('header_remove')) {
            \header_remove();
        }
        $status = ResponseContext::getStatus();
        $contentType = 'text/html; charset=UTF-8';
        foreach ($responseHeaders as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, strlen('Content-Type:')));
            }
            if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $line, $sm)) {
                $status = (int) $sm[1];
            }
        }

        return [$status, $contentType, $output, $responseHeaders];
    }

    /**
     * Format CGI/1.1 stdout (Status line + headers + body).
     *
     * @param list<string> $extraHeaders
     */
    public static function formatResponse(
        int $status,
        string $contentType,
        string $body,
        array $extraHeaders = []
    ): string {
        $reason = self::statusReason($status);
        $out = "Status: {$status} {$reason}\r\n";
        $out .= "Content-Type: {$contentType}\r\n";
        foreach ($extraHeaders as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                continue;
            }
            if (preg_match('#^HTTP/#', $line)) {
                continue;
            }
            $out .= $line."\r\n";
        }
        $out .= "\r\n".$body;

        return $out;
    }

    public static function statusReason(int $status): string
    {
        return [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Payload Too Large',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ][$status] ?? 'Unknown';
    }
}
