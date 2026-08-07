<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * HTTP/1.1 GET/HEAD via {@see VmStreamSocketNative} + VM stream I/O — no duplicate libc socket FFI (#8939).
 *
 * Pairs {@see VmHttpFetchNative} (thin alias). https:// via {@see VmHttpTlsNative} on adopted socket fd.
 *
 * php-src: ext/standard/http_fopen_wrapper.c — php_stream_url_wrap_http_ex (HTTP/1.1 request line; #28789)
 */
final class VmHttpFetchPure
{
    private const DEFAULT_TIMEOUT_SEC = 60;

    /** Last http/https transport open failure detail (strerror text; #25288). */
    private static ?string $lastOpenFailureDetail = null;

    public static function available(): bool
    {
        return VmStreamSocketNative::available();
    }

    /**
     * Detail for {@code Failed to open stream: …} after a failed HTTP wrapper open (#25288).
     *
     * php-src: ext/standard/streams.c — php_stream_xport_create errno → strerror
     */
    public static function lastOpenFailureDetail(): ?string
    {
        return self::$lastOpenFailureDetail;
    }

    public static function clearLastOpenFailureDetail(): void
    {
        self::$lastOpenFailureDetail = null;
    }

    /**
     * Connect-only probe for fopen/readfile http:// paths that lack stream handles (#25288).
     *
     * @return string|null strerror text when TCP connect fails; null when connect succeeds (caller still fails open)
     */
    public static function probeConnectFailure(string $url): ?string
    {
        self::$lastOpenFailureDetail = null;
        if (!self::available()) {
            return null;
        }

        $parts = VmString::parseUrl($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = \strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $host = (string) $parts['host'];
        if ('' === $host) {
            return null;
        }

        $useTls = 'https' === $scheme;
        $port = isset($parts['port']) ? (int) $parts['port'] : ($useTls ? 443 : 80);
        $remote = 'tcp://'.$host.':'.$port;
        [$handle, $errno, $errstr] = VmStreamSocketNative::client(
            $remote,
            (float) self::DEFAULT_TIMEOUT_SEC,
            StdlibConstants::STREAM_CLIENT_CONNECT,
            null
        );
        if (false === $handle) {
            self::$lastOpenFailureDetail = '' !== $errstr ? $errstr : 'Connection refused';

            return self::$lastOpenFailureDetail;
        }
        VmFs::fclose($handle);

        return null;
    }

    /**
     * @param array<string, mixed> $httpOptions stream_context http wrapper options
     *
     * @return string|false response body; false on transport/parse failure
     */
    public static function fetch(string $url, array $httpOptions = []): string|false
    {
        $method = self::httpOptionMethod($httpOptions);
        $response = self::request($url, $method, $httpOptions);
        if (null === $response) {
            return false;
        }

        $headers = $response['headers'];
        VmHttpLastResponseHeaders::store($headers);

        $statusCode = self::statusCodeFromStatusLine($headers[0]);
        $ignoreErrors = self::httpOptionIgnoreErrors($httpOptions);
        if (!$ignoreErrors && (null === $statusCode || $statusCode < 200 || $statusCode >= 300)) {
            return false;
        }

        $body = $response['body'];
        $contentLength = self::headerValue($headers, 'Content-Length');
        if (null !== $contentLength && \ctype_digit($contentLength)) {
            $expected = (int) $contentLength;
            if (\strlen($body) > $expected) {
                $body = \substr($body, 0, $expected);
            }
        }

        return $body;
    }

    /**
     * HEAD/GET response headers for get_headers() — any HTTP status, no body required (#3309).
     *
     * @param array<string, mixed> $httpOptions
     *
     * @return list<string>|false
     */
    public static function fetchHeaders(string $url, array $httpOptions = []): array|false
    {
        $method = self::httpOptionMethod($httpOptions, 'HEAD');
        $response = self::request($url, $method, $httpOptions);
        if (null === $response) {
            return false;
        }

        $headers = $response['headers'];
        VmHttpLastResponseHeaders::store($headers);

        return $headers;
    }

    /**
     * @param array<string, mixed> $httpOptions
     *
     * @return array{headers: list<string>, body: string}|null
     */
    private static function request(string $url, string $method, array $httpOptions = []): ?array
    {
        VmHttpLastResponseHeaders::clear();
        self::$lastOpenFailureDetail = null;

        if (!self::available()) {
            return null;
        }

        $parts = VmString::parseUrl($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = \strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $host = (string) $parts['host'];
        if ('' === $host) {
            return null;
        }

        $useTls = 'https' === $scheme;
        if ($useTls && !VmHttpTlsNative::available()) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($useTls ? 443 : 80);
        $path = isset($parts['path']) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
        if (isset($parts['query']) && '' !== $parts['query']) {
            $path .= '?'.$parts['query'];
        }

        $request = self::buildRequest($method, $path, $host, $port, $useTls, $parts, $httpOptions);

        $remote = 'tcp://'.$host.':'.$port;
        [$handle, $errno, $errstr, $socketFd] = VmStreamSocketNative::client(
            $remote,
            (float) self::DEFAULT_TIMEOUT_SEC,
            StdlibConstants::STREAM_CLIENT_CONNECT,
            null
        );
        if (false === $handle) {
            // Match stream_socket_client / Zend http wrapper strerror text (#25288).
            self::$lastOpenFailureDetail = '' !== $errstr ? $errstr : 'Connection refused';

            return null;
        }

        $tls = null;
        try {
            if ($useTls) {
                if (null === $socketFd) {
                    return null;
                }
                $tls = VmHttpTlsNative::connect($socketFd, $host);
                if (null === $tls) {
                    return null;
                }
                if (!VmHttpTlsNative::sendAll($tls, $request)) {
                    return null;
                }
                $raw = VmHttpTlsNative::recvAll($tls);
            } else {
                $written = VmFs::fwrite($handle, $request);
                if (false === $written || $written < \strlen($request)) {
                    return null;
                }
                $raw = VmFs::streamGetContents($handle);
            }
            if (false === $raw || '' === $raw) {
                return null;
            }

            $headerEnd = \strpos($raw, "\r\n\r\n");
            if (false === $headerEnd) {
                return null;
            }

            $headerBlock = \substr($raw, 0, $headerEnd);
            $body = \substr($raw, $headerEnd + 4);

            $headers = self::parseHeaderBlock($headerBlock);
            if ([] === $headers) {
                return null;
            }

            return ['headers' => $headers, 'body' => $body];
        } finally {
            if (null !== $tls) {
                VmHttpTlsNative::close($tls);
            }
            VmFs::fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $httpOptions
     */
    private static function buildRequest(
        string $method,
        string $path,
        string $host,
        int $port,
        bool $useTls,
        array $parts,
        array $httpOptions = []
    ): string {
        // php-src http_fopen_wrapper.c: request version is HTTP/1.1 (#28789).
        $request = $method.' '.$path." HTTP/1.1\r\n";
        $request .= "Host: {$host}";
        $defaultPort = $useTls ? 443 : 80;
        if ($port !== $defaultPort) {
            $request .= ":{$port}";
        }
        $request .= "\r\nConnection: close\r\n";
        if (isset($parts['user']) && '' !== $parts['user']) {
            $user = (string) $parts['user'];
            $pass = isset($parts['pass']) ? (string) $parts['pass'] : '';
            $request .= 'Authorization: Basic '.\base64_encode($user.':'.$pass)."\r\n";
        }
        // php-src: context user_agent, else INI user_agent; omit when empty (#28792).
        $userAgent = self::httpOptionUserAgent($httpOptions);
        if ('' !== $userAgent) {
            $request .= 'User-Agent: '.$userAgent."\r\n";
        }
        $request .= "\r\n";

        return $request;
    }

    /**
     * @param array<string, mixed> $httpOptions
     */
    private static function httpOptionUserAgent(array $httpOptions): string
    {
        if (isset($httpOptions['user_agent']) && \is_scalar($httpOptions['user_agent'])) {
            return (string) $httpOptions['user_agent'];
        }

        return VmIni::getUserAgent();
    }

    /**
     * @param array<string, mixed> $httpOptions
     */
    private static function httpOptionMethod(array $httpOptions, string $default = 'GET'): string
    {
        if (!isset($httpOptions['method'])) {
            return $default;
        }
        $method = \strtoupper((string) $httpOptions['method']);

        return '' !== $method ? $method : $default;
    }

    /**
     * @param array<string, mixed> $httpOptions
     */
    private static function httpOptionIgnoreErrors(array $httpOptions): bool
    {
        if (!isset($httpOptions['ignore_errors'])) {
            return false;
        }
        $v = $httpOptions['ignore_errors'];

        return true === $v || 1 === $v || '1' === $v;
    }

    /**
     * @return list<string>
     */
    private static function parseHeaderBlock(string $headerBlock): array
    {
        $lines = \preg_split("/\r\n|\n|\r/", $headerBlock);
        if (!\is_array($lines)) {
            return [];
        }

        $headers = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            $headers[] = $line;
        }

        return $headers;
    }

    private static function statusCodeFromStatusLine(string $statusLine): ?int
    {
        if (!\preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $statusLine, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @param list<string> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = \strtolower($name).':';
        foreach ($headers as $i => $line) {
            if (0 === $i) {
                continue;
            }
            if (\str_starts_with(\strtolower($line), $needle)) {
                return \trim(\substr($line, \strlen($needle)));
            }
        }

        return null;
    }
}
