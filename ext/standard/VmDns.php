<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * DNS helpers for stdlib builtins (issue #3707, native getaddrinfo #4928).
 *
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel)
 */
final class VmDns
{
    private const MAX_ADDRS = 64;

    private const AF_INET = 2;

    private const SOCK_STREAM = 1;

    private static ?\FFI $ffi = null;

    /**
     * @return HashTable|false
     */
    public static function gethostbynamel(string $hostname)
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return false;
        }

        $ips = self::resolveViaGetaddrinfo($hostname);
        if (null === $ips) {
            $ips = self::resolveViaEtcHosts($hostname);
        }
        if (null === $ips || [] === $ips) {
            return false;
        }

        $ht = new HashTable();
        foreach ($ips as $index => $ip) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($ip);
            $ht->add((string) $index, $var);
        }
        if (0 === $ht->getNumElements()) {
            return false;
        }

        return $ht;
    }

    /**
     * @return list<string>|null null when libc FFI path unavailable
     */
    private static function resolveViaGetaddrinfo(string $hostname): ?array
    {
        if (!\extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = self::ffi();
        } catch (\Throwable) {
            return null;
        }

        $hints = $ffi->new('struct addrinfo');
        $hints->ai_family = self::AF_INET;
        $hints->ai_socktype = self::SOCK_STREAM;
        $hints->ai_flags = 0;
        $hints->ai_protocol = 0;

        $resHead = $ffi->new('struct addrinfo *');
        $rc = (int) $ffi->getaddrinfo($hostname, null, \FFI::addr($hints), \FFI::addr($resHead));
        if (0 !== $rc) {
            return null;
        }

        $stored = [];
        $rp = $resHead[0];
        while (null !== $rp) {
            if (self::AF_INET === (int) $rp->ai_family && null !== $rp->ai_addr) {
                $sin = $ffi->cast('struct sockaddr_in *', $rp->ai_addr);
                $buf = $ffi->new('char[16]');
                $ntop = $ffi->inet_ntop(
                    self::AF_INET,
                    \FFI::addr($sin->sin_addr),
                    $buf,
                    16
                );
                if (null !== $ntop) {
                    $ip = \FFI::string($buf);
                    if ('' !== $ip && !\in_array($ip, $stored, true) && \count($stored) < self::MAX_ADDRS) {
                        $stored[] = $ip;
                    }
                }
            }
            $rp = $rp->ai_next;
        }
        $ffi->freeaddrinfo($resHead);

        return $stored;
    }

    private static function ffi(): \FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
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
const char *inet_ntop(int af, const void *src, char *dst, socklen_t size);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('libc getaddrinfo FFI unavailable');
    }

    /**
     * Pure-PHP fallback when FFI is absent (reads /etc/hosts only).
     *
     * @return list<string>|null
     */
    private static function resolveViaEtcHosts(string $hostname): ?array
    {
        $path = '/etc/hosts';
        if (!\is_readable($path)) {
            return null;
        }
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return null;
        }

        $hostname = \strtolower($hostname);
        $stored = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = \preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            $ip = $parts[0];
            if (!\preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
                continue;
            }
            for ($i = 1, $n = \count($parts); $i < $n; ++$i) {
                if (\strtolower($parts[$i]) === $hostname) {
                    if (!\in_array($ip, $stored, true) && \count($stored) < self::MAX_ADDRS) {
                        $stored[] = $ip;
                    }
                    break;
                }
            }
        }

        return $stored;
    }
}
