<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * net_get_interfaces() via /sys/class/net — no libc getifaddrs FFI (#8988).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 * Linux: walks interface names + operstate; loopback IPv4 from well-known 127.0.0.1.
 */
final class VmNetInterfacesPure
{
    private const AF_INET = 2;

    private const IFF_UP = 1;

    private const IFF_LOOPBACK = 8;

    private const IFF_RUNNING = 64;

    public static function available(): bool
    {
        return 'Linux' === \PHP_OS_FAMILY && \is_dir('/sys/class/net');
    }

    /**
     * @return array<string, array{up: bool, unicast: list<array<string, int|string>>}>|false
     */
    public static function collect(): array|false
    {
        if (!self::available()) {
            return false;
        }

        $names = VmDirNative::listSorted('/sys/class/net');
        if (false === $names) {
            return false;
        }

        $root = [];
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $operstate = self::readSmallFile('/sys/class/net/'.$name.'/operstate');
            $up = \in_array($operstate, ['up', 'unknown'], true);
            $unicast = [];
            if ('lo' === $name) {
                $unicast[] = [
                    'family' => self::AF_INET,
                    'address' => '127.0.0.1',
                    'netmask' => '255.0.0.0',
                    'flags' => self::IFF_UP | self::IFF_LOOPBACK | self::IFF_RUNNING,
                ];
            }
            $root[$name] = [
                'up' => $up,
                'unicast' => $unicast,
            ];
        }

        return [] === $root ? false : $root;
    }

    private static function readSmallFile(string $path): string
    {
        if (!\is_readable($path)) {
            return '';
        }
        if (VmFsReadNative::available()) {
            $raw = VmFsReadNative::read($path);
            if (\is_string($raw) && '' !== $raw) {
                return \trim($raw);
            }
        }
        $raw = @\file_get_contents($path);

        return \is_string($raw) ? \trim($raw) : '';
    }
}
