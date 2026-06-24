<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * net_get_interfaces(): /sys pure path first, libc getifaddrs FFI fallback (#8988, #6106).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 * JIT/AOT: StringNetInterfacesJit.php via NetInterfacesJitHelper
 */
final class VmNetInterfacesNative
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const IFF_UP = 1;

    private const IFF_BROADCAST = 2;

    private const IFF_POINTOPOINT = 16;

    private const INET_ADDRSTRLEN = 16;

    private const INET6_ADDRSTRLEN = 46;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array<string, array{up: bool, unicast: list<array<string, int|string>>}>|false
     */
    public static function collect(): array|false
    {
        $pure = VmNetInterfacesPure::collect();
        if (false !== $pure) {
            return $pure;
        }

        return self::collectViaFfi();
    }

    /**
     * @return array<string, array{up: bool, unicast: list<array<string, int|string>>}>|false
     */
    private static function collectViaFfi(): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $listHead = $ffi->new('struct ifaddrs*');
            if (0 !== (int) $ffi->getifaddrs(\FFI::addr($listHead))) {
                return false;
            }
            $root = [];
            $current = $listHead;
            while (null !== $current) {
                $name = \FFI::string($current->ifa_name);
                if (!isset($root[$name])) {
                    $root[$name] = [
                        'up' => 0 !== ($current->ifa_flags & self::IFF_UP),
                        'unicast' => [],
                    ];
                }
                $unicast = self::appendUnicast(
                    $ffi,
                    (int) $current->ifa_flags,
                    $current->ifa_addr,
                    $current->ifa_netmask,
                    0 !== ($current->ifa_flags & self::IFF_BROADCAST) ? $current->ifa_broadaddr : null,
                    0 !== ($current->ifa_flags & self::IFF_POINTOPOINT) ? $current->ifa_dstaddr : null
                );
                if (null !== $unicast) {
                    $root[$name]['unicast'][] = $unicast;
                }
                $current = $current->ifa_next;
            }
            $ffi->freeifaddrs($listHead);

            return $root;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, int|string>|null
     */
    private static function appendUnicast(
        \FFI $ffi,
        int $flags,
        ?\FFI\CData $addr,
        ?\FFI\CData $netmask,
        ?\FFI\CData $broadcast,
        ?\FFI\CData $ptp
    ): ?array {
        $entry = ['flags' => $flags];
        if (null !== $addr) {
            $family = (int) $addr->sa_family;
            $entry['family'] = $family;
            $host = self::sockaddrHost($ffi, $addr);
            if (null !== $host) {
                $entry['address'] = $host;
            }
        }
        $mask = self::sockaddrHost($ffi, $netmask);
        if (null !== $mask) {
            $entry['netmask'] = $mask;
        }
        $bcast = self::sockaddrHost($ffi, $broadcast);
        if (null !== $bcast) {
            $entry['broadcast'] = $bcast;
        }
        $ptpHost = self::sockaddrHost($ffi, $ptp);
        if (null !== $ptpHost) {
            $entry['ptp'] = $ptpHost;
        }
        if (!isset($entry['family']) && !isset($entry['address'])) {
            return null;
        }

        return $entry;
    }

    private static function sockaddrHost(\FFI $ffi, ?\FFI\CData $addr): ?string
    {
        if (null === $addr) {
            return null;
        }
        $family = (int) $addr->sa_family;
        if (self::AF_INET === $family) {
            $sin = $ffi->cast('struct sockaddr_in*', $addr);
            $buf = $ffi->new('char['.self::INET_ADDRSTRLEN.']');

            return self::coerceNtop(
                $ffi->inet_ntop(
                    self::AF_INET,
                    \FFI::addr($sin->sin_addr),
                    \FFI::addr($buf[0]),
                    self::INET_ADDRSTRLEN
                ),
                $buf
            );
        }
        if (self::AF_INET6 === $family) {
            $sin6 = $ffi->cast('struct sockaddr_in6*', $addr);
            $buf = $ffi->new('char['.self::INET6_ADDRSTRLEN.']');

            return self::coerceNtop(
                $ffi->inet_ntop(
                    self::AF_INET6,
                    \FFI::addr($sin6->sin6_addr),
                    \FFI::addr($buf[0]),
                    self::INET6_ADDRSTRLEN
                ),
                $buf
            );
        }

        return null;
    }

    private static function coerceNtop(mixed $ptr, \FFI\CData $buf): ?string
    {
        if (null === $ptr || (\is_int($ptr) && 0 === $ptr)) {
            return null;
        }
        $host = \FFI::string($buf);
        if ('' === $host) {
            return null;
        }

        return $host;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned short sa_family_t;
typedef unsigned int uint32_t;
typedef unsigned int socklen_t;

struct in_addr {
    uint32_t s_addr;
};

struct sockaddr {
    sa_family_t sa_family;
    char sa_data[14];
};

struct sockaddr_in {
    sa_family_t sin_family;
    unsigned short sin_port;
    struct in_addr sin_addr;
    char sin_zero[8];
};

struct in6_addr {
    unsigned char s6_addr[16];
};

struct sockaddr_in6 {
    sa_family_t sin6_family;
    unsigned short sin6_port;
    uint32_t sin6_flowinfo;
    struct in6_addr sin6_addr;
    uint32_t sin6_scope_id;
};

struct ifaddrs {
    struct ifaddrs *ifa_next;
    char *ifa_name;
    unsigned int ifa_flags;
    struct sockaddr *ifa_addr;
    struct sockaddr *ifa_netmask;
    struct sockaddr *ifa_broadaddr;
    struct sockaddr *ifa_dstaddr;
    void *ifa_data;
};

int getifaddrs(struct ifaddrs **ifap);
void freeifaddrs(struct ifaddrs *ifa);
const char *inet_ntop(int af, const void *src, char *dst, socklen_t size);
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
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
