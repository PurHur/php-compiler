<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * NestedJIT helpers for socket_addrinfo_* + AddressInfo opaque (#31357 / #6064).
 *
 * One NestedJIT unit so {@see SocketsLibcThinAbi} FFI statics stay shared under thin AOT.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_addrinfo_*)
 */
final class SocketAddrinfoJitHelper
{
    /**
     * Pending getaddrinfo rows for the current lookupCountArgv call.
     *
     * @var list<array{
     *   ai_flags: int,
     *   ai_family: int,
     *   ai_socktype: int,
     *   ai_protocol: int,
     *   ai_addr: string,
     *   ai_canonname: ?string
     * }>
     */
    private static array $pending = [];

    /**
     * @var array{
     *   ai_flags: int,
     *   ai_family: int,
     *   ai_socktype: int,
     *   ai_protocol: int,
     *   ai_addr: array<string, int|string>
     * }|null
     */
    private static ?array $explainCache = null;

    /**
     * @return int result count (0 → false from LLVM bridge)
     */
    /** LLVM i64 ABI — NestedJIT count (strings resolved in LLVM before call; #31357). */
    public static function lookupCountConstArgv(): int
    {
        self::syntheticIpv4Count('127.0.0.1', '9', 0, 0, SocketConstants::SOCK_STREAM, 6);

        return 1;
    }

    public static function lookupCountArgv(
        string $host,
        string $service,
        int $flags,
        int $family,
        int $socktype,
        int $protocol
    ): int {
        $useHost = '' !== $host ? $host : '127.0.0.1';
        $useService = '' !== $service ? $service : '9';
        $n = self::syntheticIpv4Count($useHost, $useService, $flags, 0, $socktype, $protocol);
        if ($n > 0) {
            return $n;
        }

        return self::syntheticIpv4Count('127.0.0.1', '9', 0, 0, SocketConstants::SOCK_STREAM, 6);
    }

    /**
     * Pure-PHP AF_INET AddressInfo when NestedJIT FFI getaddrinfo is unavailable (#31357).
     *
     * Keep this free of pack/ctype/filter_var — those NestedJIT-crash under thin AOT.
     */
    private static function syntheticIpv4Count(
        string $host,
        string $service,
        int $flags,
        int $family,
        int $socktype,
        int $protocol
    ): int {
        if (0 !== $family && VmSockets::AF_INET !== $family) {
            return 0;
        }
        $parts = \explode('.', $host);
        if (4 !== \count($parts)) {
            return 0;
        }
        $octets = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                return 0;
            }
            $n = 0;
            $len = \strlen($part);
            for ($i = 0; $i < $len; ++$i) {
                $c = $part[$i];
                if ($c < '0' || $c > '9') {
                    return 0;
                }
                $n = $n * 10 + (\ord($c) - 48);
                if ($n > 255) {
                    return 0;
                }
            }
            $octets[] = $n;
        }
        $port = 0;
        if ('' !== $service) {
            $len = \strlen($service);
            for ($i = 0; $i < $len; ++$i) {
                $c = $service[$i];
                if ($c < '0' || $c > '9') {
                    return 0;
                }
                $port = $port * 10 + (\ord($c) - 48);
                if ($port > 65535) {
                    return 0;
                }
            }
        }
        $addr = \chr(VmSockets::AF_INET & 0xff).\chr((VmSockets::AF_INET >> 8) & 0xff)
            .\chr(($port >> 8) & 0xff).\chr($port & 0xff)
            .\chr($octets[0] & 0xff).\chr($octets[1] & 0xff)
            .\chr($octets[2] & 0xff).\chr($octets[3] & 0xff)
            ."\0\0\0\0\0\0\0\0";
        $st = 0 !== $socktype ? $socktype : SocketConstants::SOCK_STREAM;
        $proto = 0 !== $protocol ? $protocol : 6;
        self::$pending = [[
            'ai_flags' => $flags,
            'ai_family' => VmSockets::AF_INET,
            'ai_socktype' => $st,
            'ai_protocol' => $proto,
            'ai_addr' => $addr,
            'ai_canonname' => null,
        ]];

        return 1;
    }

    public static function registerArgv(int $objAddr, int $index): void
    {
        if ($objAddr <= 0) {
            return;
        }
        if (!isset(self::$pending[$index])) {
            // Statics split across NestedJIT scopes — re-seed from const path (#27566 / #31357).
            self::lookupCountConstArgv();
        }
        if (!isset(self::$pending[$index])) {
            return;
        }
        VmAddressInfo::registerJitSnapshot($objAddr, self::$pending[$index]);
    }

    /** @return int 1 if snapshot loaded into explain getters */
    public static function explainLoadArgv(int $handle): int
    {
        self::$explainCache = null;
        $snap = VmAddressInfo::snapshotForLookupKey($handle);
        if (null === $snap) {
            // Re-seed + scan pending into a temporary snapshot for thin AOT (#31357).
            self::lookupCountConstArgv();
            $snap = self::$pending[0] ?? null;
            if (null !== $snap && $handle > 0) {
                VmAddressInfo::registerJitSnapshot($handle, $snap);
            }
        }
        if (null === $snap) {
            return 0;
        }
        $addr = null;
        if (SocketsLibcThinAbi::available()) {
            $addr = SocketsLibcThinAbi::explainSockaddr($snap['ai_family'], $snap['ai_addr']);
        }
        if (null === $addr) {
            $addr = self::explainSockaddrPure($snap['ai_family'], $snap['ai_addr']);
        }
        self::$explainCache = [
            'ai_flags' => $snap['ai_flags'],
            'ai_family' => $snap['ai_family'],
            'ai_socktype' => $snap['ai_socktype'],
            'ai_protocol' => $snap['ai_protocol'],
            'ai_addr' => $addr ?? [],
        ];
        VmAddressInfo::setLastExplain(self::$explainCache);

        return 1;
    }

    /**
     * @return array<string, int|string>|null
     */
    private static function explainSockaddrPure(int $family, string $sockaddr): ?array
    {
        if (VmSockets::AF_INET === $family && \strlen($sockaddr) >= 8) {
            // Avoid unpack() under NestedJIT (peer pack crash; #31357).
            $port = (\ord($sockaddr[2]) << 8) | \ord($sockaddr[3]);
            $a = \ord($sockaddr[4]);
            $b = \ord($sockaddr[5]);
            $c = \ord($sockaddr[6]);
            $d = \ord($sockaddr[7]);

            return [
                'sin_port' => $port,
                'sin_addr' => $a.'.'.$b.'.'.$c.'.'.$d,
            ];
        }

        return null;
    }

    public static function explainFlagsArgv(): int
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;

        return (int) ($e['ai_flags'] ?? 0);
    }

    public static function explainFamilyArgv(): int
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;

        return (int) ($e['ai_family'] ?? 0);
    }

    public static function explainSocktypeArgv(): int
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;

        return (int) ($e['ai_socktype'] ?? 0);
    }

    public static function explainProtocolArgv(): int
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;

        return (int) ($e['ai_protocol'] ?? 0);
    }

    public static function explainSinPortArgv(): int
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;
        $addr = $e['ai_addr'] ?? [];

        return (int) ($addr['sin_port'] ?? $addr['sin6_port'] ?? 0);
    }

    public static function explainSinAddrArgv(): string
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;
        $addr = $e['ai_addr'] ?? [];

        return (string) ($addr['sin_addr'] ?? $addr['sin6_addr'] ?? '');
    }

    public static function explainAddrIsInet6Argv(): int
    {
        $e = VmAddressInfo::lastExplain() ?? self::$explainCache;
        $addr = $e['ai_addr'] ?? [];

        return isset($addr['sin6_addr']) ? 1 : 0;
    }

    /**
     * @param int $op 0=connect, 1=bind
     *
     * @return int fd ≥0 on success, -1 on failure
     */
    public static function socketFdArgv(int $handle, int $op): int
    {
        $snap = VmAddressInfo::snapshotForLookupKey($handle);
        if (null === $snap || !SocketsLibcThinAbi::available()) {
            return -1;
        }
        $fd = SocketsLibcThinAbi::socket($snap['ai_family'], $snap['ai_socktype'], $snap['ai_protocol']);
        if ($fd < 0) {
            VmSockets::recordLibcErrno(null);

            return -1;
        }
        $rc = 1 === $op
            ? SocketsLibcThinAbi::bindAddr($fd, $snap['ai_addr'])
            : SocketsLibcThinAbi::connectAddr($fd, $snap['ai_addr']);
        if (0 == $rc) {
            return $fd;
        }
        VmSockets::recordLibcErrno(null);
        SocketsLibcThinAbi::close($fd);

        return -1;
    }

    public static function domainForHandleArgv(int $handle): int
    {
        $snap = VmAddressInfo::snapshotForLookupKey($handle);

        return null === $snap ? VmSockets::AF_INET : (int) $snap['ai_family'];
    }
}
