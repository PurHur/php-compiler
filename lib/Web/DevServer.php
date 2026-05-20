<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Shared HTTP/1.1 dev-server helpers for bin/serve.php and bin/serve-aot.php.
 */
final class DevServer
{
    /** Maximum request headers mapped into $_SERVER (issue #77). */
    public const MAX_REQUEST_HEADERS = 128;

    /** Maximum length per header value passed to user scripts. */
    public const MAX_HEADER_VALUE_LEN = 8192;

    public static function run(string $listen, string $docroot, callable $handlePhpRequest): void
    {
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
            self::handleConnection($conn, $docroot, $handlePhpRequest);
            fclose($conn);
        }
    }

    public static function handleConnection($conn, string $docroot, callable $handlePhpRequest): void
    {
        $raw = self::readRequest($conn);
        if (null === $raw) {
            self::respond($conn, 400, 'text/plain', "Bad Request\n");

            return;
        }

        [$method, $path, $query, $headers, $body, $serverProtocol] = $raw;
        $path = parse_url($path, PHP_URL_PATH) ?? '/';
        if ('/' === $path) {
            $path = '/example.php';
        }

        if (!self::isSafeUrlPath($path)) {
            self::respond($conn, 403, 'text/plain', "Forbidden\n");

            return;
        }

        if (!str_ends_with(strtolower($path), '.php')) {
            $static = self::resolveDocrootFile($docroot, $path);
            if (null !== $static) {
                $bytes = file_get_contents($static);
                if (false === $bytes) {
                    self::respond($conn, 500, 'text/plain', "Internal Server Error\n");

                    return;
                }
                self::respond($conn, 200, self::guessContentType($static), $bytes);

                return;
            }
        }

        $scriptName = $path;
        $pathInfo = '';
        if (preg_match('#^(.+\.php)(/.*)?$#', $path, $pm)) {
            $scriptName = $pm[1];
            $pathInfo = $pm[2] ?? '';
        }

        $script = $docroot.$scriptName;
        if (!is_file($script)) {
            self::respond($conn, 404, 'text/plain', "Not Found\n");

            return;
        }

        $scriptFilename = realpath($script);
        if (false === $scriptFilename) {
            $scriptFilename = $script;
        }

        $requestUri = $scriptName.$pathInfo;
        if ('' !== $query) {
            $requestUri .= '?'.$query;
        }

        $cgiEnv = [
            'REQUEST_METHOD' => $method,
            'QUERY_STRING' => $query,
            'REQUEST_BODY' => $body,
            'SCRIPT_NAME' => $scriptName,
            'SCRIPT_FILENAME' => $scriptFilename,
            'REQUEST_URI' => $requestUri,
            'DOCUMENT_ROOT' => $docroot,
            'SERVER_PROTOCOL' => $serverProtocol,
        ];
        if ('' !== $pathInfo) {
            $cgiEnv['PATH_INFO'] = $pathInfo;
        }

        self::clearHttpServerKeys();
        $cgiEnv = array_merge($cgiEnv, Superglobals::applyHttpHeaders($headers));
        $contentLength = self::contentLengthForRequest($headers, $body);
        if (null !== $contentLength) {
            $cgiEnv['CONTENT_LENGTH'] = $contentLength;
            $_SERVER['CONTENT_LENGTH'] = $contentLength;
            putenv('CONTENT_LENGTH='.$contentLength);
        }

        putenv('REQUEST_METHOD='.$method);
        putenv('QUERY_STRING='.$query);
        putenv('REQUEST_BODY='.$body);
        putenv('SCRIPT_NAME='.$scriptName);
        putenv('SCRIPT_FILENAME='.$scriptFilename);
        putenv('REQUEST_URI='.$requestUri);
        putenv('DOCUMENT_ROOT='.$docroot);
        putenv('SERVER_PROTOCOL='.$serverProtocol);
        if ('' !== $pathInfo) {
            putenv('PATH_INFO='.$pathInfo);
        } else {
            putenv('PATH_INFO');
        }

        try {
            [$status, $contentType, $output, $extraHeaders] = $handlePhpRequest($script, $cgiEnv);
        } catch (\Throwable $e) {
            self::logException($e);
            self::respond($conn, 500, 'text/plain', self::formatExceptionBody($e));

            return;
        }

        self::respond($conn, $status, $contentType, $output, $extraHeaders);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: array<string, string>, 4: string, 5: string}|null
     */
    public static function readRequest($conn): ?array
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

        if (!preg_match('#^(\S+)\s+(\S+)\s+(HTTP/\S+)#', $lines, $m)) {
            return null;
        }

        $method = $m[1];
        $target = $m[2];
        $serverProtocol = $m[3];
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

        return [$method, $path, $query, $headers, $body, $serverProtocol];
    }

    /**
     * CGI CONTENT_LENGTH for incoming requests when Content-Length was sent.
     *
     * Uses the bytes actually read (not the header alone). Absent for chunked
     * requests without Content-Length (issue #287).
     */
    public static function contentLengthForRequest(array $headers, string $body): ?string
    {
        if (!isset($headers['content-length'])) {
            return null;
        }

        return (string) strlen($body);
    }

    /**
     * Map an HTTP header name to a CGI $_SERVER key (e.g. host → HTTP_HOST).
     */
    public static function headerNameToServerKey(string $name): string
    {
        return Superglobals::headerNameToServerKey($name);
    }

    /**
     * @param array<string, string> $headers lowercase header name => value
     *
     * @return array<string, string> HTTP_* keys for $_SERVER / CGI env
     */
    public static function httpHeadersToServerVars(array $headers): array
    {
        $serverVars = [];
        $count = 0;
        foreach ($headers as $name => $value) {
            if ($count >= self::MAX_REQUEST_HEADERS) {
                break;
            }
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                continue;
            }
            if (strlen($value) > self::MAX_HEADER_VALUE_LEN) {
                $value = substr($value, 0, self::MAX_HEADER_VALUE_LEN);
            }
            $serverVars[self::headerNameToServerKey($name)] = $value;
            ++$count;
        }

        return $serverVars;
    }

    public static function clearHttpServerKeys(): void
    {
        foreach (array_keys($_SERVER) as $key) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
    }

    public static function isSafeUrlPath(string $path): bool
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

    public static function resolveDocrootFile(string $docroot, string $urlPath): ?string
    {
        $candidate = $docroot.$urlPath;
        $real = realpath($candidate);
        if (false === $real || !is_file($real)) {
            return null;
        }
        $prefix = $docroot.DIRECTORY_SEPARATOR;
        if ($real !== $docroot && !str_starts_with($real, $prefix)) {
            return null;
        }

        return $real;
    }

    public static function guessContentType(string $filePath): string
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

    public static function isServeDebug(): bool
    {
        $v = getenv('PHP_COMPILER_DEBUG');

        return false !== $v && '' !== $v && '0' !== $v;
    }

    public static function logException(\Throwable $e): void
    {
        fwrite(STDERR, '[serve] '.get_class($e).': '.$e->getMessage()."\n");
        fwrite(STDERR, $e->getTraceAsString()."\n");
    }

    public static function formatExceptionBody(\Throwable $e): string
    {
        if (!self::isServeDebug()) {
            return "Internal Server Error\n";
        }

        return get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
    }

    /**
     * @param list<string> $extraHeaders
     */
    public static function respond($conn, int $status, string $contentType, string $body, array $extraHeaders = []): void
    {
        $reason = [
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
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ][$status] ?? 'Unknown';

        $out = "HTTP/1.1 {$status} {$reason}\r\n";
        $out .= "Content-Type: {$contentType}\r\n";
        $out .= 'Content-Length: '.strlen($body)."\r\n";
        foreach ($extraHeaders as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                continue;
            }
            if (preg_match('#^HTTP/#', $line)) {
                continue;
            }
            $out .= $line."\r\n";
        }
        $out .= "Connection: close\r\n\r\n";
        $out .= $body;
        fwrite($conn, $out);
    }

    /**
     * Parse CGI-style stdout (Status/headers + body) from an AOT binary.
     *
     * @return array{0: int, 1: string, 2: string, 3: list<string>}
     */
    public static function parseCgiOutput(string $raw): array
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $status = 200;
        $contentType = 'text/html; charset=UTF-8';
        $extraHeaders = [];

        foreach (preg_split("/\r\n|\n/", $headerBlock) as $line) {
            if ('' === $line) {
                continue;
            }
            if (preg_match('#^Status:\s*(\d+)#i', $line, $sm)) {
                $status = (int) $sm[1];
                continue;
            }
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, strlen('Content-Type:')));

                continue;
            }
            $extraHeaders[] = $line;
        }

        return [$status, $contentType, $body, $extraHeaders];
    }
}
