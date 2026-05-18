#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal HTTP/1.1 dev server for web examples (VM mode).
 *
 * Usage: php bin/serve.php [host:port] [docroot]
 * Example: php bin/serve.php 127.0.0.1:8080 examples/001-SimpleWeb
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\Web\Superglobals;

$listen = $argv[1] ?? '127.0.0.1:8080';
$docroot = $argv[2] ?? getcwd();
if (!is_dir($docroot)) {
    fwrite(STDERR, "Docroot not found: {$docroot}\n");
    exit(1);
}
$docroot = realpath($docroot);
if (false === $docroot) {
    fwrite(STDERR, "Could not resolve docroot\n");
    exit(1);
}

if (!preg_match('#^(.+):(\d+)$#', $listen, $m)) {
    fwrite(STDERR, "Listen address must be host:port, got: {$listen}\n");
    exit(1);
}
$host = $m[1];
$port = (int) $m[2];

$errno = 0;
$errstr = '';
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, "Could not bind {$listen}: {$errstr}\n");
    exit(1);
}

fwrite(STDERR, "PHP-Compiler serve: http://{$host}:{$port}/ (docroot {$docroot})\n");

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (false === $conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    handleConnection($conn, $docroot);
    fclose($conn);
}

function handleConnection($conn, string $docroot): void
{
    $raw = readRequest($conn);
    if (null === $raw) {
        respond($conn, 400, 'text/plain', "Bad Request\n");

        return;
    }

    [$method, $path, $query, $headers, $body] = $raw;
    $path = parse_url($path, PHP_URL_PATH) ?? '/';
    if ('/' === $path) {
        $path = '/example.php';
    }

    if (!isSafeUrlPath($path)) {
        respond($conn, 403, 'text/plain', "Forbidden\n");

        return;
    }

    if (!str_ends_with(strtolower($path), '.php')) {
        $static = resolveDocrootFile($docroot, $path);
        if (null !== $static) {
            $bytes = file_get_contents($static);
            if (false === $bytes) {
                respond($conn, 500, 'text/plain', "Internal Server Error\n");

                return;
            }
            respond($conn, 200, guessContentType($static), $bytes);

            return;
        }
    }

    $scriptName = $path;
    $pathInfo = '';
    if (preg_match('#^(.+\.php)(/.*)?$#', $path, $pm)) {
        $scriptName = $pm[1];
        $pathInfo = $pm[2] ?? '';
    }

    $script = $docroot . $scriptName;
    if (!is_file($script)) {
        respond($conn, 404, 'text/plain', "Not Found\n");

        return;
    }

    $requestUri = $scriptName . $pathInfo;
    if ('' !== $query) {
        $requestUri .= '?' . $query;
    }

    putenv('REQUEST_METHOD=' . $method);
    putenv('QUERY_STRING=' . $query);
    putenv('REQUEST_BODY=' . $body);
    putenv('SCRIPT_NAME=' . $scriptName);
    putenv('REQUEST_URI=' . $requestUri);
    if ('' !== $pathInfo) {
        putenv('PATH_INFO=' . $pathInfo);
    } else {
        putenv('PATH_INFO');
    }

    $code = file_get_contents($script);
    if (false === $code) {
        respond($conn, 500, 'text/plain', "Internal Server Error\n");

        return;
    }

    ob_start();
    try {
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, $query, $body);
        $block = $runtime->parseAndCompile($code, $script);
        $runtime->run($block);
        $output = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        logException($e);
        respond($conn, 500, 'text/plain', formatExceptionBody($e));

        return;
    }

    $responseHeaders = headers_list();
    header_remove();
    $status = 200;
    $contentType = 'text/html; charset=UTF-8';
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            $contentType = trim(substr($line, strlen('Content-Type:')));
        }
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $line, $sm)) {
            $status = (int) $sm[1];
        }
    }

    respond($conn, $status, $contentType, $output, $responseHeaders);
}

/**
 * @return array{0: string, 1: string, 2: string, 3: array<string, string>, 4: string}|null
 */
function readRequest($conn): ?array
{
    $lines = '';
    while (!feof($conn)) {
        $chunk = fgets($conn);
        if (false === $chunk) {
            break;
        }
        $lines .= $chunk;
        if ("\r\n" === $chunk) {
            break;
        }
    }

    if (!preg_match('#^(\S+)\s+(\S+)\s+HTTP/#', $lines, $m)) {
        return null;
    }

    $method = $m[1];
    $target = $m[2];
    $path = parse_url($target, PHP_URL_PATH) ?? '/';
    $query = parse_url($target, PHP_URL_QUERY) ?? '';
    if (false === $query) {
        $query = '';
    }

    $headers = [];
    foreach (explode("\r\n", $lines) as $line) {
        if ('' === $line || false === strpos($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    $body = '';
    if (isset($headers['content-length'])) {
        $len = (int) $headers['content-length'];
        while (strlen($body) < $len && !feof($conn)) {
            $body .= fread($conn, $len - strlen($body));
        }
    }

    return [$method, $path, $query, $headers, $body];
}

function isSafeUrlPath(string $path): bool
{
    if ('' === $path || '/' !== $path[0]) {
        return false;
    }
    foreach (explode('/', $path) as $segment) {
        if ('..' === $segment) {
            return false;
        }
    }

    return true;
}

function resolveDocrootFile(string $docroot, string $urlPath): ?string
{
    $candidate = $docroot . $urlPath;
    $real = realpath($candidate);
    if (false === $real || !is_file($real)) {
        return null;
    }
    $prefix = $docroot . DIRECTORY_SEPARATOR;
    if ($real !== $docroot && !str_starts_with($real, $prefix)) {
        return null;
    }

    return $real;
}

function guessContentType(string $filePath): string
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    return [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ][$ext] ?? 'application/octet-stream';
}

function isServeDebug(): bool
{
    $v = getenv('PHP_COMPILER_DEBUG');

    return false !== $v && '' !== $v && '0' !== $v;
}

function logException(\Throwable $e): void
{
    fwrite(STDERR, '[serve] ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
}

function formatExceptionBody(\Throwable $e): string
{
    if (!isServeDebug()) {
        return "Internal Server Error\n";
    }

    return get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

function respond($conn, int $status, string $contentType, string $body, array $extraHeaders = []): void
{
    $reason = [
        200 => 'OK',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
    ][$status] ?? 'OK';

    $out = "HTTP/1.1 {$status} {$reason}\r\n";
    $out .= "Content-Type: {$contentType}\r\n";
    $out .= 'Content-Length: ' . strlen($body) . "\r\n";
    foreach ($extraHeaders as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            continue;
        }
        if (preg_match('#^HTTP/#', $line)) {
            continue;
        }
        $out .= $line . "\r\n";
    }
    $out .= "Connection: close\r\n\r\n";
    $out .= $body;
    fwrite($conn, $out);
}
