<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Network service/protocol lookups (ext/standard/network.c parity, issue #3593).
 *
 * Prefer host PHP libc results when available; fall back to parsing /etc/protocols and
 * /etc/services so minimal containers without NSS databases still match Zend when files exist.
 */
final class VmNetwork
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $protocolCache = null;

    /** @var array<string, array<string, array<string, mixed>>>|null */
    private static ?array $serviceCache = null;

    /**
     * @return array<string, mixed>|false
     */
    public static function getprotobyname(string $name)
    {
        if (\function_exists('getprotobyname')) {
            $host = @\getprotobyname($name);
            if (\is_array($host)) {
                return $host;
            }
        }

        $key = strtolower($name);
        foreach (self::protocolTable() as $proto) {
            if ($proto['name'] === $key) {
                return $proto;
            }
            foreach ($proto['aliases'] as $alias) {
                if (strtolower($alias) === $key) {
                    return $proto;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getservbyname(string $service, string $protocol)
    {
        if (\function_exists('getservbyname')) {
            $host = @\getservbyname($service, $protocol);
            if (\is_array($host)) {
                return $host;
            }
        }

        $serviceKey = strtolower($service);
        $protoKey = strtolower($protocol);
        $byProto = self::serviceTable()[$protoKey] ?? [];
        foreach ($byProto as $entry) {
            if ($entry['name'] === $serviceKey) {
                return $entry;
            }
            foreach ($entry['aliases'] as $alias) {
                if (strtolower($alias) === $serviceKey) {
                    return $entry;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function protocolTable(): array
    {
        if (null !== self::$protocolCache) {
            return self::$protocolCache;
        }
        self::$protocolCache = [];
        $path = '/etc/protocols';
        if (!is_readable($path)) {
            return self::$protocolCache;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            if (str_contains($line, '#')) {
                $line = trim(explode('#', $line, 2)[0]);
            }
            $parts = preg_split('/\s+/', $line, 3);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            $name = strtolower($parts[0]);
            $numberPart = $parts[1];
            if (!preg_match('/^(\d+)/', $numberPart, $m)) {
                continue;
            }
            $aliases = [];
            if (isset($parts[2]) && '' !== $parts[2]) {
                foreach (preg_split('/\s+/', $parts[2]) ?: [] as $alias) {
                    if ('' !== $alias) {
                        $aliases[] = $alias;
                    }
                }
            }
            self::$protocolCache[$name] = [
                'name' => $name,
                'aliases' => $aliases,
                'number' => (int) $m[1],
            ];
        }

        return self::$protocolCache;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function serviceTable(): array
    {
        if (null !== self::$serviceCache) {
            return self::$serviceCache;
        }
        self::$serviceCache = [];
        $path = '/etc/services';
        if (!is_readable($path)) {
            return self::$serviceCache;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            if (str_contains($line, '#')) {
                $line = trim(explode('#', $line, 2)[0]);
            }
            $parts = preg_split('/\s+/', $line);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            $name = strtolower(array_shift($parts));
            $portProto = array_shift($parts);
            if (null === $portProto || !preg_match('/^(\d+)\/([a-z0-9]+)$/i', $portProto, $m)) {
                continue;
            }
            $aliases = [];
            foreach ($parts as $alias) {
                if ('' !== $alias) {
                    $aliases[] = $alias;
                }
            }
            $proto = strtolower($m[2]);
            self::$serviceCache[$proto][$name] = [
                'name' => $name,
                'aliases' => $aliases,
                'port' => (int) $m[1],
                'protocol' => $proto,
            ];
        }

        return self::$serviceCache;
    }
}
