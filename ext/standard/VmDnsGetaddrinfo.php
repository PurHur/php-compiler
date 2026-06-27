<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin libc getaddrinfo FFI for IPv4 forward DNS (php-src ext/standard/dns.c, #12483).
 *
 * Preserves duplicate A records from the resolver (e.g. glibc localhost → two 127.0.0.1).
 * Falls back to pure-PHP {@see VmDns} when FFI is unavailable.
 */
final class VmDnsGetaddrinfo
{
    private const AF_INET = 2;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return list<string>|null null when FFI unavailable or resolution failed
     */
    public static function resolveIpv4List(string $hostname): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $hints = $ffi->new('struct addrinfo');
        $hints->ai_family = self::AF_INET;
        $hints->ai_socktype = 1;
        $hints->ai_protocol = 0;
        $hints->ai_flags = 0;

        $resHead = $ffi->new('struct addrinfo *');
        $rc = (int) $ffi->getaddrinfo($hostname, null, \FFI::addr($hints), \FFI::addr($resHead));
        if (0 !== $rc) {
            return null;
        }

        $ips = [];
        try {
            $rp = $resHead[0];
            while (null !== $rp) {
                if (self::AF_INET === (int) $rp->ai_family) {
                    $addrlen = (int) $rp->ai_addrlen;
                    if ($addrlen >= 8) {
                        $raw = \FFI::string($rp->ai_addr, $addrlen);
                        $packed = \substr($raw, 4, 4);
                        if (4 === \strlen($packed)) {
                            $ip = \inet_ntop($packed);
                            if (\is_string($ip) && '' !== $ip) {
                                $ips[] = $ip;
                            }
                        }
                    }
                }
                $rp = $rp->ai_next;
            }
        } finally {
            $ffi->freeaddrinfo($resHead);
        }

        return [] === $ips ? null : $ips;
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

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
