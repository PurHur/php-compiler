<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc UDP socket exchange for DNS queries; falls back to {@see VmDnsUdpPure} when FFI unavailable (#8937).
 *
 * php-src: ext/standard/dns.c — UDP DNS transport
 */
final class VmDnsUdpNative
{
    private const AF_INET = 2;

    private const SOCK_DGRAM = 2;

    private const SOL_SOCKET = 1;

    private const SO_RCVTIMEO = 20;

    /** htons(53) on little-endian hosts */
    private const DNS_PORT_NET = 13568;

    private const TIMEOUT_SEC = 2;

    private const BUF_SIZE = 4096;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmDnsUdpPure::available();
    }

    public static function exchange(string $nameserver, string $query): ?string
    {
        if ('' === $query) {
            return null;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return VmDnsUdpPure::exchange($nameserver, $query);
        }

        $sock = (int) $ffi->socket(self::AF_INET, self::SOCK_DGRAM, 0);
        if ($sock < 0) {
            return null;
        }

        try {
            $tv = $ffi->new('struct timeval');
            $tv->tv_sec = self::TIMEOUT_SEC;
            $tv->tv_usec = 0;
            if (0 !== (int) $ffi->setsockopt(
                $sock,
                self::SOL_SOCKET,
                self::SO_RCVTIMEO,
                \FFI::addr($tv),
                \FFI::sizeof($tv)
            )) {
                return null;
            }

            $sin = $ffi->new('struct sockaddr_in');
            $sin->sin_family = self::AF_INET;
            $sin->sin_port = self::DNS_PORT_NET;
            if (1 !== (int) $ffi->inet_pton(self::AF_INET, $nameserver, \FFI::addr($sin->sin_addr))) {
                return null;
            }

            $sa = $ffi->cast('struct sockaddr *', \FFI::addr($sin));
            if (0 !== (int) $ffi->connect($sock, $sa, \FFI::sizeof($sin))) {
                return null;
            }

            $queryLen = \strlen($query);
            $sent = (int) $ffi->send($sock, $query, $queryLen, 0);
            if ($sent < 0 || $sent !== $queryLen) {
                return null;
            }

            $buf = $ffi->new('unsigned char['.self::BUF_SIZE.']');
            $received = (int) $ffi->recv($sock, \FFI::addr($buf[0]), self::BUF_SIZE, 0);
            if ($received <= 0) {
                return null;
            }

            return \FFI::string($buf, $received);
        } finally {
            $ffi->close($sock);
        }
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
int inet_pton(int af, const char *src, void *dst);
typedef unsigned long size_t;
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
