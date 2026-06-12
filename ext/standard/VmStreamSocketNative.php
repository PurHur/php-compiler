<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * libc TCP/UDP client sockets without host Zend stream wrappers (#8097, #3202).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_client)
 */
final class VmStreamSocketNative
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const SOCK_STREAM = 1;

    private const SOCK_DGRAM = 2;

    private const SOL_SOCKET = 1;

    private const SO_SNDTIMEO = 21;

    private const IPPROTO_TCP = 6;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array{0: resource|false, 1: int, 2: string}
     */
    public static function client(
        string $remote,
        float $timeout,
        int $flags,
        ?Variable $contextVar = null
    ): array {
        if (!self::available()) {
            return [false, 0, 'VmStreamSocketNative FFI unavailable'];
        }

        $parsed = self::parseRemoteSocket($remote);
        if (null === $parsed) {
            return [false, 0, 'Unable to parse remote socket path'];
        }

        if ('ssl' === $parsed['transport'] || 'tls' === $parsed['transport']) {
            return [false, 0, 'ssl:// transport is not supported in this compiler build'];
        }

        if ('unix' === $parsed['transport']) {
            return [false, 0, 'unix:// transport is not supported in this compiler build'];
        }

        $contextTimeout = self::connectTimeoutFromContext($contextVar);
        if (null !== $contextTimeout) {
            $timeout = $contextTimeout;
        }

        $sockType = 'udp' === $parsed['transport'] ? self::SOCK_DGRAM : self::SOCK_STREAM;
        $port = (string) $parsed['port'];

        $ffi = self::ffi();
        if (null === $ffi) {
            return [false, 0, 'VmStreamSocketNative FFI unavailable'];
        }

        $hints = $ffi->new('struct addrinfo');
        $hints->ai_family = \str_contains($parsed['host'], ':') ? self::AF_INET6 : self::AF_INET;
        $hints->ai_socktype = $sockType;
        $hints->ai_protocol = self::SOCK_STREAM === $sockType ? self::IPPROTO_TCP : 0;
        $hints->ai_flags = 0;

        $resHead = $ffi->new('struct addrinfo *');
        $rc = (int) $ffi->getaddrinfo($parsed['host'], $port, \FFI::addr($hints), \FFI::addr($resHead));
        if (0 !== $rc) {
            $err = self::gaiStrerror($ffi, $rc);

            return [false, 0, $err];
        }

        $lastErrno = 0;
        $lastErrstr = 'Connection refused';
        $connectedFd = -1;

        try {
            $rp = $resHead[0];
            while (null !== $rp) {
                $sock = (int) $ffi->socket((int) $rp->ai_family, $sockType, (int) $rp->ai_protocol);
                if ($sock < 0) {
                    $lastErrno = self::errno($ffi);
                    $lastErrstr = self::strerror($ffi, $lastErrno);
                    $rp = $rp->ai_next;

                    continue;
                }

                if ($timeout >= 0.0) {
                    self::applySocketTimeout($ffi, $sock, $timeout);
                }

                $connectRc = (int) $ffi->connect($sock, $rp->ai_addr, (int) $rp->ai_addrlen);
                if (0 === $connectRc) {
                    $connectedFd = $sock;
                    break;
                }

                $lastErrno = self::errno($ffi);
                $lastErrstr = self::strerror($ffi, $lastErrno);
                $ffi->close($sock);
                $rp = $rp->ai_next;
            }
        } finally {
            $ffi->freeaddrinfo($resHead);
        }

        if ($connectedFd < 0) {
            return [false, $lastErrno, $lastErrstr];
        }

        try {
            $dupFd = (int) $ffi->dup($connectedFd);
            if ($dupFd < 0) {
                $errno = self::errno($ffi);
                $ffi->close($connectedFd);

                return [false, $errno, self::strerror($ffi, $errno)];
            }

            $ffi->close($connectedFd);

            $mode = self::SOCK_DGRAM === $sockType ? 'r+' : 'r+';
            $stream = @\fopen('php://fd/'.$dupFd, $mode);
            if (false === $stream) {
                $ffi->close($dupFd);

                return [false, 0, 'Unable to create stream from socket'];
            }

            return [$stream, 0, ''];
        } catch (\Throwable) {
            if ($connectedFd >= 0) {
                $ffi->close($connectedFd);
            }

            return [false, 0, 'Unable to create stream from socket'];
        }
    }

    /**
     * @return array{transport: string, host: string, port: int}|null
     */
    private static function parseRemoteSocket(string $remote): ?array
    {
        $remote = \trim($remote);
        if ('' === $remote) {
            return null;
        }

        $transport = 'tcp';
        $rest = $remote;

        if (\preg_match('#^([a-z][a-z0-9+.-]*)://(.+)$#i', $remote, $schemeMatch)) {
            $transport = \strtolower($schemeMatch[1]);
            $rest = $schemeMatch[2];
        }

        if (\preg_match('#^\[([^\]]+)\](?::(\d+))?$#', $rest, $ipv6Match)) {
            $host = $ipv6Match[1];
            $port = isset($ipv6Match[2]) ? (int) $ipv6Match[2] : 0;

            return ['transport' => $transport, 'host' => $host, 'port' => $port];
        }

        if (\preg_match('#^([^:/]+)(?::(\d+))?$#', $rest, $match)) {
            return [
                'transport' => $transport,
                'host' => $match[1],
                'port' => isset($match[2]) ? (int) $match[2] : 0,
            ];
        }

        return null;
    }

    private static function connectTimeoutFromContext(?Variable $contextVar): ?float
    {
        if (null === $contextVar) {
            return null;
        }
        $resolved = $contextVar->resolveIndirect();
        if (!VmStreamContext::isRepresentation($resolved)) {
            return null;
        }

        $options = VmStreamContext::getOptionsHashTable($resolved);
        $socket = $options->find('socket');
        if (null === $socket) {
            return null;
        }
        $socketResolved = $socket->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $socketResolved->type) {
            return null;
        }
        $timeoutVar = $socketResolved->toArray()->find('connect_timeout');
        if (null === $timeoutVar) {
            return null;
        }
        $timeoutResolved = $timeoutVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $timeoutResolved->type) {
            return (float) $timeoutResolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $timeoutResolved->type) {
            return $timeoutResolved->toFloat();
        }

        return null;
    }

    private static function applySocketTimeout(\FFI $ffi, int $sock, float $timeout): void
    {
        if ($timeout < 0.0) {
            return;
        }

        $sec = (int) \floor($timeout);
        $usec = (int) \round(($timeout - (float) $sec) * 1_000_000.0);

        $tv = $ffi->new('struct timeval');
        $tv->tv_sec = $sec;
        $tv->tv_usec = $usec;
        $ffi->setsockopt($sock, self::SOL_SOCKET, self::SO_SNDTIMEO, \FFI::addr($tv), \FFI::sizeof($tv));
    }

    private static function errno(\FFI $ffi): int
    {
        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    private static function strerror(\FFI $ffi, int $errno): string
    {
        if ($errno <= 0) {
            return 'Connection failed';
        }
        $msg = $ffi->strerror($errno);

        return \is_string($msg) && '' !== $msg ? $msg : 'Connection failed';
    }

    private static function gaiStrerror(\FFI $ffi, int $code): string
    {
        if (\function_exists('gai_strerror')) {
            $msg = \gai_strerror($code);

            return \is_string($msg) && '' !== $msg ? $msg : 'getaddrinfo failed';
        }
        $msg = $ffi->gai_strerror($code);

        return \is_string($msg) && '' !== $msg ? $msg : 'getaddrinfo failed';
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
typedef unsigned short int in_port_t;
typedef long ssize_t;

struct in_addr {
    unsigned int s_addr;
};

struct sockaddr_in {
    sa_family_t sin_family;
    in_port_t sin_port;
    struct in_addr sin_addr;
    unsigned char sin_zero[8];
};

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
int setsockopt(int sockfd, int level, int optname, const void *optval, socklen_t optlen);
int close(int fd);
int dup(int oldfd);
int *__errno_location(void);
char *strerror(int errnum);
int getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **res);
void freeaddrinfo(struct addrinfo *res);
const char *gai_strerror(int errcode);
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
