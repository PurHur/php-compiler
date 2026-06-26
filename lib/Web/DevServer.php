<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Shared HTTP/1.1 dev-server helpers for bin/serve.php, bin/serve-jit.php, and bin/serve-aot.php.
 */
final class DevServer
{
    /** Maximum request headers mapped into $_SERVER (issue #77). */
    public const MAX_REQUEST_HEADERS = 128;

    /** Maximum length per header value passed to user scripts. */
    public const MAX_HEADER_VALUE_LEN = 8192;

    /** Maximum decoded request body size (issue #77, #287). */
    public const MAX_REQUEST_BODY = 8_388_608;

    /** HTTP status from the last failed {@see readRequest()} (400 or 413). */
    public static int $readRequestRejectStatus = 400;

    /**
     * Effective POST/body byte limit (issue #77, #697).
     *
     * Override with {@code PHP_COMPILER_MAX_BODY} (positive integer, capped at MAX_REQUEST_BODY).
     */
    public static function maxRequestBody(): int
    {
        $raw = getenv('PHP_COMPILER_MAX_BODY');
        if (false === $raw || '' === $raw) {
            return self::MAX_REQUEST_BODY;
        }
        $n = (int) $raw;
        if ($n <= 0) {
            return self::MAX_REQUEST_BODY;
        }

        return min($n, self::MAX_REQUEST_BODY);
    }

    public static function run(
        string $listen,
        string $docroot,
        callable $handlePhpRequest,
        ?array $manifest = null,
        ?string $projectDir = null
    ): void {
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
            self::handleConnection($conn, $docroot, $handlePhpRequest, $manifest, $projectDir);
            fclose($conn);
        }
    }

    public static function handleConnection(
        $conn,
        string $docroot,
        callable $handlePhpRequest,
        ?array $manifest = null,
        ?string $projectDir = null
    ): void {
        $remoteAddr = null;
        $remotePort = null;
        $peer = stream_socket_get_name($conn, true);
        if (is_string($peer)) {
            $parsed = self::parsePeerAddress($peer);
            if (null !== $parsed) {
                [$remoteAddr, $remotePort] = $parsed;
            }
        }

        $raw = self::readRequest($conn);
        if (null === $raw) {
            $status = self::$readRequestRejectStatus;
            $message = 413 === $status ? "Payload Too Large\n" : "Bad Request\n";
            self::respond($conn, $status, 'text/plain', $message);

            return;
        }

        [$method, $path, $query, $headers, $body, $serverProtocol] = $raw;
        $path = parse_url($path, PHP_URL_PATH) ?? '/';
        if ('/' === $path) {
            $path = self::resolveDirectoryIndex($docroot, $manifest, $projectDir);
        }

        if (!self::isSafeUrlPath($path)) {
            self::respond($conn, 403, 'text/plain', "Forbidden\n");

            return;
        }

        if (!str_ends_with(strtolower($path), '.php')) {
            $assetsDir = null;
            if (null !== $projectDir) {
                $assetsDir = ProjectManifest::resolveAssetsDir($projectDir, $manifest);
            }
            $static = self::resolveStaticFile($docroot, $path, $assetsDir);
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
        if (null !== $remoteAddr && null !== $remotePort) {
            $cgiEnv['REMOTE_ADDR'] = $remoteAddr;
            $cgiEnv['REMOTE_PORT'] = $remotePort;
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
        if (null !== $remoteAddr && null !== $remotePort) {
            putenv('REMOTE_ADDR='.$remoteAddr);
            putenv('REMOTE_PORT='.$remotePort);
            $_SERVER['REMOTE_ADDR'] = $remoteAddr;
            $_SERVER['REMOTE_PORT'] = $remotePort;
        } else {
            putenv('REMOTE_ADDR');
            putenv('REMOTE_PORT');
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['REMOTE_PORT']);
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
        self::$readRequestRejectStatus = 400;
        $maxBody = self::maxRequestBody();
        $buf = '';
        while (!feof($conn)) {
            $chunk = fread($conn, 8192);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $buf .= $chunk;
            if (str_contains($buf, "\r\n\r\n") || str_contains($buf, "\n\n")) {
                break;
            }
        }

        $headerEnd = strpos($buf, "\r\n\r\n");
        $sepLen = 4;
        if (false === $headerEnd) {
            $headerEnd = strpos($buf, "\n\n");
            $sepLen = 2;
        }
        if (false === $headerEnd) {
            return null;
        }

        $headerBlock = substr($buf, 0, $headerEnd);
        $body = substr($buf, $headerEnd + $sepLen);

        if (!preg_match('#^(\S+)\s+(\S+)\s+(HTTP/\S+)#', $headerBlock, $m)) {
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
        foreach (preg_split("/\r\n|\n/", $headerBlock) as $line) {
            if ('' === $line || false === strpos($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        if (self::hasChunkedTransferEncoding($headers)) {
            $decoded = self::readChunkedBody($conn, $body);
            if (null === $decoded) {
                return null;
            }
            $body = $decoded;
        } elseif (isset($headers['content-length'])) {
            $len = (int) $headers['content-length'];
            if ($len > $maxBody) {
                self::$readRequestRejectStatus = 413;

                return null;
            }
            while (strlen($body) < $len && !feof($conn)) {
                $chunk = fread($conn, $len - strlen($body));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }
            if (strlen($body) > $len) {
                $body = substr($body, 0, $len);
            }
        } elseif (self::methodMayHaveBody($method) && 'HTTP/1.0' === $serverProtocol) {
            while (!feof($conn)) {
                if (strlen($body) >= $maxBody) {
                    self::$readRequestRejectStatus = 413;

                    return null;
                }
                $chunk = fread($conn, min(8192, $maxBody - strlen($body)));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }
        }

        return [$method, $path, $query, $headers, $body, $serverProtocol];
    }

    public static function methodMayHaveBody(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * @param array<string, string> $headers lowercase header name => value
     */
    public static function hasChunkedTransferEncoding(array $headers): bool
    {
        if (!isset($headers['transfer-encoding'])) {
            return false;
        }
        foreach (explode(',', strtolower($headers['transfer-encoding'])) as $coding) {
            if ('chunked' === trim($coding)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode HTTP/1.1 chunked body (RFC 7230). $buffer holds bytes after the header block.
     */
    public static function readChunkedBody($conn, string $buffer): ?string
    {
        $result = '';
        while (true) {
            $lineEnd = strpos($buffer, "\r\n");
            if (false === $lineEnd) {
                if (feof($conn)) {
                    return null;
                }
                $more = fread($conn, 8192);
                if (false === $more || '' === $more) {
                    return null;
                }
                $buffer .= $more;
                continue;
            }

            $line = substr($buffer, 0, $lineEnd);
            $buffer = substr($buffer, $lineEnd + 2);
            $semi = strpos($line, ';');
            if (false !== $semi) {
                $line = substr($line, 0, $semi);
            }
            $line = trim($line);
            if ('' === $line || !ctype_xdigit($line)) {
                return null;
            }
            $chunkSize = (int) hexdec($line);
            $maxBody = self::maxRequestBody();
            if ($chunkSize < 0 || $chunkSize > $maxBody || strlen($result) + $chunkSize > $maxBody) {
                if ($chunkSize > $maxBody || strlen($result) + $chunkSize > $maxBody) {
                    self::$readRequestRejectStatus = 413;
                }

                return null;
            }
            if (0 === $chunkSize) {
                return $result;
            }

            while (strlen($buffer) < $chunkSize + 2) {
                if (feof($conn)) {
                    return null;
                }
                $need = $chunkSize + 2 - strlen($buffer);
                $more = fread($conn, max(8192, $need));
                if (false === $more || '' === $more) {
                    return null;
                }
                $buffer .= $more;
            }

            $result .= substr($buffer, 0, $chunkSize);
            $buffer = substr($buffer, $chunkSize);
            if (!str_starts_with($buffer, "\r\n")) {
                return null;
            }
            $buffer = substr($buffer, 2);
        }
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
     * Parse stream_socket_get_name($socket, true) into address and port (issue #295).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parsePeerAddress(string $peer): ?array
    {
        if ('' === $peer) {
            return null;
        }
        if ('[' === $peer[0]) {
            if (!preg_match('#^\[([^\]]+)\]:(\d+)$#', $peer, $m)) {
                return null;
            }

            return [$m[1], $m[2]];
        }
        $colon = strrpos($peer, ':');
        if (false === $colon) {
            return null;
        }
        $addr = substr($peer, 0, $colon);
        $port = substr($peer, $colon + 1);
        if ('' === $addr || '' === $port || !ctype_digit($port)) {
            return null;
        }

        return [$addr, $port];
    }

    /**
     * @param array<string, string> $headers lowercase header name => value
     *
     * HTTP_* keys for $_SERVER / CGI env.
     *
     * @return array<string, string>
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

    /**
     * URL path for GET / when no script is in the request (issue #254).
     *
     * Prefer index.php (standard front controller); fall back to example.php for
     * shipped examples/001-SimpleWeb. Optional phpc.json "index" overrides when set.
     */
    public static function resolveDirectoryIndex(string $docroot, ?array $manifest = null, ?string $projectDir = null): string
    {
        if (null !== $manifest && isset($manifest['index']) && is_string($manifest['index']) && '' !== $manifest['index']) {
            $index = $manifest['index'];
            if ('/' === $index[0]) {
                if (is_file($index)) {
                    return $index;
                }
            } else {
                $base = $projectDir ?? realpath($docroot);
                if (false === $base || null === $base) {
                    $base = $projectDir ?? $docroot;
                }
                $candidate = $base.'/'.$index;
                if (is_file($candidate)) {
                    $docrootBase = realpath($docroot);
                    if (false === $docrootBase) {
                        $docrootBase = $docroot;
                    }
                    $relativeBase = str_starts_with($candidate, $docrootBase)
                        ? $docrootBase
                        : $base;
                    $urlPath = '/'.ltrim(str_replace('\\', '/', substr($candidate, strlen($relativeBase))), '/');
                    if ('' === $urlPath || '/' === $urlPath) {
                        return '/index.php';
                    }

                    return $urlPath;
                }
            }
        }

        if (is_file($docroot.'/index.php')) {
            return '/index.php';
        }
        if (is_file($docroot.'/example.php')) {
            return '/example.php';
        }

        return '/index.php';
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

    public static function resolveStaticFile(string $docroot, string $urlPath, ?string $assetsDir = null): ?string
    {
        $static = self::resolveDocrootFile($docroot, $urlPath);
        if (null !== $static) {
            return $static;
        }
        if (null === $assetsDir) {
            return null;
        }

        return self::resolveAssetsFile($assetsDir, $urlPath);
    }

    public static function resolveDocrootFile(string $docroot, string $urlPath): ?string
    {
        return self::resolveFileUnderRoot($docroot, $urlPath);
    }

    /**
     * Map /assets/* URLs to files under the manifest assets directory (issue #594).
     */
    public static function resolveAssetsFile(string $assetsDir, string $urlPath): ?string
    {
        $prefix = '/assets';
        if (!str_starts_with($urlPath, $prefix.'/') && $urlPath !== $prefix) {
            return null;
        }

        $relative = substr($urlPath, strlen($prefix));
        if ('' === $relative) {
            $relative = '/';
        }

        return self::resolveFileUnderRoot($assetsDir, $relative);
    }

    private static function resolveFileUnderRoot(string $root, string $urlPath): ?string
    {
        $rootReal = realpath($root);
        if (false === $rootReal) {
            return null;
        }

        $candidate = $rootReal.$urlPath;
        $real = realpath($candidate);
        if (false === $real || !is_file($real)) {
            return null;
        }
        $prefix = $rootReal.DIRECTORY_SEPARATOR;
        if ($real !== $rootReal && !str_starts_with($real, $prefix)) {
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
            413 => 'Payload Too Large',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ][$status] ?? 'Unknown';

        $out = "HTTP/1.1 {$status} {$reason}\r\n";
        $out .= "Content-Type: {$contentType}\r\n";
        $out .= 'Content-Length: '.strlen($body)."\r\n";
        foreach ($extraHeaders as $line) {
            if ('' === $line) {
                continue;
            }
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

        // AOT binaries may emit a single CRLF after headers (no blank line).
        if ('' === $body && preg_match('/\A(.+?\r\n)(.+)\z/s', $raw, $m)) {
            $headerBlock = rtrim($m[1], "\r\n");
            $body = $m[2];
        }

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
