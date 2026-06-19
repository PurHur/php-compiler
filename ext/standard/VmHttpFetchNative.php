<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc TCP HTTP/1.0 GET for file_get_contents(http://…) without host PHP wrapper (#8552).
 *
 * Populates {@see VmHttpLastResponseHeaders} for http_get_last_response_headers() (#7236, #9752).
 * https:// via {@see VmHttpTlsNative} libssl FFI (php-src main/streams/xp_ssl.c).
 *
 * php-src: ext/standard/streams.c — http wrapper
 */
final class VmHttpFetchNative
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const SOCK_STREAM = 1;

    private const SOL_SOCKET = 1;

    private const SO_RCVTIMEO = 20;

    private const SO_SNDTIMEO = 21;

    private const IPPROTO_TCP = 6;

    private const DEFAULT_TIMEOUT_SEC = 60;

    private const RECV_CHUNK = 8192;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return string|false response body; false on transport/parse failure
     */
    /**
     * @param array<string, mixed> $httpOptions stream_context http wrapper options
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
     * @return list<string>|false
     */
    /**
     * @param array<string, mixed> $httpOptions
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

        $request = $method.' '.$path." HTTP/1.0\r\n";
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
        $request .= "\r\n";

        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $sock = self::connect($ffi, $host, (string) $port);
        if ($sock < 0) {
            return null;
        }

        $tls = null;
        try {
            self::applySocketTimeout($ffi, $sock, (float) self::DEFAULT_TIMEOUT_SEC);

            if ($useTls) {
                $tls = VmHttpTlsNative::connect($sock, $host);
                if (null === $tls) {
                    return null;
                }
                if (!VmHttpTlsNative::sendAll($tls, $request)) {
                    return null;
                }
                $raw = VmHttpTlsNative::recvAll($tls);
            } else {
                if (!self::sendAll($ffi, $sock, $request)) {
                    return null;
                }
                $raw = self::recvAll($ffi, $sock);
            }
            if ('' === $raw) {
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
            $ffi->close($sock);
        }
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

    private static function connect(\FFI $ffi, string $host, string $port): int
    {
        $hints = $ffi->new('struct addrinfo');
        $hints->ai_family = \str_contains($host, ':') ? self::AF_INET6 : self::AF_INET;
        $hints->ai_socktype = self::SOCK_STREAM;
        $hints->ai_protocol = self::IPPROTO_TCP;
        $hints->ai_flags = 0;

        $resHead = $ffi->new('struct addrinfo *');
        $rc = (int) $ffi->getaddrinfo($host, $port, \FFI::addr($hints), \FFI::addr($resHead));
        if (0 !== $rc) {
            return -1;
        }

        $connected = -1;
        try {
            $rp = $resHead[0];
            while (null !== $rp) {
                $sock = (int) $ffi->socket((int) $rp->ai_family, self::SOCK_STREAM, (int) $rp->ai_protocol);
                if ($sock < 0) {
                    $rp = $rp->ai_next;

                    continue;
                }

                if (0 === (int) $ffi->connect($sock, $rp->ai_addr, (int) $rp->ai_addrlen)) {
                    $connected = $sock;
                    break;
                }

                $ffi->close($sock);
                $rp = $rp->ai_next;
            }
        } finally {
            $ffi->freeaddrinfo($resHead);
        }

        return $connected;
    }

    private static function sendAll(\FFI $ffi, int $sock, string $data): bool
    {
        $offset = 0;
        $len = \strlen($data);
        while ($offset < $len) {
            $remaining = \substr($data, $offset);
            $sent = (int) $ffi->send($sock, $remaining, \strlen($remaining), 0);
            if ($sent <= 0) {
                return false;
            }
            $offset += $sent;
        }

        return true;
    }

    private static function recvAll(\FFI $ffi, int $sock): string
    {
        $buf = '';
        while (true) {
            $chunk = $ffi->new('unsigned char['.self::RECV_CHUNK.']');
            $n = (int) $ffi->recv($sock, \FFI::addr($chunk[0]), self::RECV_CHUNK, 0);
            if ($n <= 0) {
                break;
            }
            $buf .= \FFI::string($chunk, $n);
        }

        return $buf;
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

    private static function applySocketTimeout(\FFI $ffi, int $sock, float $timeout): void
    {
        $sec = (int) \floor($timeout);
        $usec = (int) \round(($timeout - (float) $sec) * 1_000_000.0);

        $tv = $ffi->new('struct timeval');
        $tv->tv_sec = $sec;
        $tv->tv_usec = $usec;
        $ffi->setsockopt($sock, self::SOL_SOCKET, self::SO_RCVTIMEO, \FFI::addr($tv), \FFI::sizeof($tv));
        $ffi->setsockopt($sock, self::SOL_SOCKET, self::SO_SNDTIMEO, \FFI::addr($tv), \FFI::sizeof($tv));
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned int socklen_t;
typedef unsigned short int sa_family_t;
typedef long ssize_t;

struct sockaddr {
    sa_family_t sa_family;
    char sa_data[14];
};

struct addrinfo {
    int ai_flags;
    int ai_family;
    int ai_socktype;
    int ai_protocol;
    socklen_t ai_addrlen;
    struct sockaddr *ai_addr;
    char *ai_canonname;
    struct addrinfo *ai_next;
};

struct timeval {
    long tv_sec;
    long tv_usec;
};

int socket(int domain, int type, int protocol);
int connect(int sockfd, const struct sockaddr *addr, socklen_t addrlen);
ssize_t send(int sockfd, const void *buf, size_t len, int flags);
ssize_t recv(int sockfd, void *buf, size_t len, int flags);
int setsockopt(int sockfd, int level, int optname, const void *optval, socklen_t optlen);
int close(int fd);
int getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **res);
void freeaddrinfo(struct addrinfo *res);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
