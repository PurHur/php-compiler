<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;

/**
 * net_get_interfaces() for compiled JIT/AOT modules (#8988, #23715, #26942, php-in-PHP).
 *
 * NestedJIT must not return {@see HashTable} under thin AOT — that path pointer-casts a
 * miscompiled object to `__hashtable__*` and segfaults / returns garbage counts (#26942; peer
 * sys_getloadavg #27294 / gethostbynamel #22397). Expose scalars; the LLVM bridge materializes
 * the HT in {@see \PHPCompiler\JIT\Builtin\StringNetInterfacesJit}.
 *
 * NestedJIT data path uses only {@see file_get_contents} + string ops on /proc+/sys
 * (VmNetInterfacesPure::collect / opendir / hexdec NestedJIT miscompile — #26942).
 *
 * SSOT for VM: {@see VmNetInterfaces::get}. php-src: ext/standard/net.c
 */
final class NetInterfacesJitHelper
{
    public const HAS_FLAGS = 1;

    public const HAS_FAMILY = 2;

    public const HAS_ADDRESS = 4;

    public const HAS_NETMASK = 8;

    public const HAS_BROADCAST = 16;

    public const HAS_PTP = 32;

    private const AF_PACKET = 17;

    private const IFF_UP = 0x1;

    private const IFF_RUNNING = 0x40;

    private const IFF_LOWER_UP = 0x10000;

    /** Host / unit tests — build a real VM HashTable (not NestedJIT under thin AOT). */
    public static function resolve(): ?HashTable
    {
        $ifaces = VmNetInterfaces::get();

        return false === $ifaces ? null : $ifaces;
    }

    public static function resolveOk(): int
    {
        if (self::ifaceCount() > 0) {
            return 1;
        }
        ErrorReporter::report(
            ErrorReporter::E_WARNING,
            'getifaddrs() failed: unable to enumerate network interfaces'
        );

        return 0;
    }

    public static function ifaceCount(): int
    {
        $raw = @\file_get_contents('/proc/net/dev');
        if (false === $raw || '' === $raw) {
            return 0;
        }
        $n = 0;
        $lines = \explode("\n", $raw);
        $lineCount = \count($lines);
        for ($li = 0; $li < $lineCount; ++$li) {
            $line = \trim($lines[$li]);
            if ('' === $line) {
                continue;
            }
            $pos = \strpos($line, ':');
            if (false === $pos) {
                continue;
            }
            $name = \trim(\substr($line, 0, $pos));
            if ('' === $name || 'Inter-' === $name || 'face' === $name) {
                continue;
            }
            ++$n;
        }

        return $n;
    }

    public static function ifaceNameAt(int $index): string
    {
        $raw = @\file_get_contents('/proc/net/dev');
        if (false === $raw || '' === $raw) {
            return '';
        }
        $i = 0;
        $lines = \explode("\n", $raw);
        $lineCount = \count($lines);
        for ($li = 0; $li < $lineCount; ++$li) {
            $line = \trim($lines[$li]);
            if ('' === $line) {
                continue;
            }
            $pos = \strpos($line, ':');
            if (false === $pos) {
                continue;
            }
            $name = \trim(\substr($line, 0, $pos));
            if ('' === $name || 'Inter-' === $name || 'face' === $name) {
                continue;
            }
            if ($i === $index) {
                return $name;
            }
            ++$i;
        }

        return '';
    }

    public static function ifaceUpAt(int $index): int
    {
        $name = self::ifaceNameAt($index);
        if ('' === $name) {
            return 0;
        }

        return (0 !== (self::ifaFlags($name) & self::IFF_UP)) ? 1 : 0;
    }

    public static function unicastCountAt(int $iface): int
    {
        // AF_PACKET row always present when the iface exists (php-src iface_get_contents shape).
        return '' === self::ifaceNameAt($iface) ? 0 : 1;
    }

    public static function unicastMaskAt(int $iface, int $u): int
    {
        return self::HAS_FLAGS | self::HAS_FAMILY;
    }

    public static function unicastFlagsAt(int $iface, int $u): int
    {
        $name = self::ifaceNameAt($iface);
        if ('' === $name) {
            return 0;
        }

        return self::ifaFlags($name);
    }

    public static function unicastFamilyAt(int $iface, int $u): int
    {
        return self::AF_PACKET;
    }

    public static function unicastAddressAt(int $iface, int $u): string
    {
        return '';
    }

    public static function unicastNetmaskAt(int $iface, int $u): string
    {
        return '';
    }

    public static function unicastBroadcastAt(int $iface, int $u): string
    {
        return '';
    }

    public static function unicastPtpAt(int $iface, int $u): string
    {
        return '';
    }

    private static function ifaFlags(string $name): int
    {
        $base = '/sys/class/net/'.$name.'/';
        $raw = self::rf($base.'flags');
        $flags = 0;
        if ('' !== $raw) {
            if (0 === \strncmp($raw, '0x', 2) || 0 === \strncmp($raw, '0X', 2)) {
                $flags = (int) \intval(\substr($raw, 2), 16);
            } else {
                $flags = (int) $raw;
            }
        }
        $op = self::rf($base.'operstate');
        $c = self::rf($base.'carrier');
        if ('1' === $c || 'up' === $op || 'unknown' === $op) {
            $flags |= self::IFF_RUNNING | self::IFF_LOWER_UP;
        }

        return $flags;
    }

    private static function rf(string $path): string
    {
        $raw = @\file_get_contents($path);
        if (false === $raw) {
            return '';
        }

        return \trim($raw);
    }
}
