<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Network service/protocol lookups (issue #3650, #5333).
 *
 * Config reads (/etc/protocols, /etc/services) via {@see VmFs::file()} / {@see VmFsReadNative} — no host \\file() (#8538).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c
 */
final class VmNetworkServices
{
    private const PROTOCOLS_PATHS = [
        '/etc/protocols',
    ];

    private const SERVICES_PATHS = [
        '/etc/services',
    ];

    /** @var list<array{name: string, number: int, aliases: list<string>}>|null */
    private static ?array $protocolEntries = null;

    /** @var list<array{name: string, port: int, protocol: string, aliases: list<string>}>|null */
    private static ?array $serviceEntries = null;

    /** @return string|false */
    public static function getprotobynumber(int $number)
    {
        foreach (self::protocolEntries() as $entry) {
            if ($entry['number'] === $number) {
                return $entry['name'];
            }
        }

        return false;
    }

    /** @return int|false */
    public static function getprotobyname(string $name)
    {
        $key = strtolower($name);
        foreach (self::protocolEntries() as $entry) {
            if (strtolower($entry['name']) === $key) {
                return $entry['number'];
            }
            foreach ($entry['aliases'] as $alias) {
                if (strtolower($alias) === $key) {
                    return $entry['number'];
                }
            }
        }

        return false;
    }

    /** @return string|false */
    public static function getservbyport(int $port, string $protocol)
    {
        if ('' === $protocol) {
            return false;
        }
        $proto = strtolower($protocol);
        foreach (self::serviceEntries() as $entry) {
            if ($entry['port'] === $port && strtolower($entry['protocol']) === $proto) {
                return $entry['name'];
            }
        }

        return false;
    }

    /** @return int|false */
    public static function getservbyname(string $service, string $protocol)
    {
        if ('' === $protocol) {
            return false;
        }
        $serviceKey = strtolower($service);
        $protoKey = strtolower($protocol);
        foreach (self::serviceEntries() as $entry) {
            if (strtolower($entry['protocol']) !== $protoKey) {
                continue;
            }
            if (strtolower($entry['name']) === $serviceKey) {
                return $entry['port'];
            }
            foreach ($entry['aliases'] as $alias) {
                if (strtolower($alias) === $serviceKey) {
                    return $entry['port'];
                }
            }
        }

        return false;
    }

    /**
     * Lookup tables for JIT/AOT helper generation at link time (#9777).
     *
     * @return array{
     *     protoByNumber: list<array{number: int, name: string}>,
     *     protoByName: list<array{key: string, number: int}>,
     *     serviceByPort: list<array{port: int, protocol: string, name: string}>,
     *     serviceByName: list<array{service: string, protocol: string, port: int}>
     * }
     */
    public static function buildJitTables(): array
    {
        $protoByNumber = [];
        $protoByName = [];
        foreach (self::protocolEntries() as $entry) {
            $protoByNumber[] = ['number' => $entry['number'], 'name' => $entry['name']];
            $protoByName[] = ['key' => strtolower($entry['name']), 'number' => $entry['number']];
            foreach ($entry['aliases'] as $alias) {
                $protoByName[] = ['key' => strtolower($alias), 'number' => $entry['number']];
            }
        }

        $serviceByPort = [];
        $serviceByName = [];
        foreach (self::serviceEntries() as $entry) {
            $serviceByPort[] = [
                'port' => $entry['port'],
                'protocol' => strtolower($entry['protocol']),
                'name' => $entry['name'],
            ];
            $serviceByName[] = [
                'service' => strtolower($entry['name']),
                'protocol' => strtolower($entry['protocol']),
                'port' => $entry['port'],
            ];
            foreach ($entry['aliases'] as $alias) {
                $serviceByName[] = [
                    'service' => strtolower($alias),
                    'protocol' => strtolower($entry['protocol']),
                    'port' => $entry['port'],
                ];
            }
        }

        return [
            'protoByNumber' => $protoByNumber,
            'protoByName' => $protoByName,
            'serviceByPort' => $serviceByPort,
            'serviceByName' => $serviceByName,
        ];
    }

    /**
     * @return list<array{name: string, number: int, aliases: list<string>}>
     */
    private static function protocolEntries(): array
    {
        if (null !== self::$protocolEntries) {
            return self::$protocolEntries;
        }
        self::$protocolEntries = [];
        foreach (self::PROTOCOLS_PATHS as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $parsed = self::parseProtocolFile($path);
            if ([] !== $parsed) {
                self::$protocolEntries = $parsed;

                return self::$protocolEntries;
            }
        }

        return self::$protocolEntries;
    }

    /**
     * @return list<array{name: string, port: int, protocol: string, aliases: list<string>}>
     */
    private static function serviceEntries(): array
    {
        if (null !== self::$serviceEntries) {
            return self::$serviceEntries;
        }
        self::$serviceEntries = [];
        foreach (self::SERVICES_PATHS as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $parsed = self::parseServiceFile($path);
            if ([] !== $parsed) {
                self::$serviceEntries = $parsed;

                return self::$serviceEntries;
            }
        }

        return self::$serviceEntries;
    }

    /**
     * @return list<array{name: string, number: int, aliases: list<string>}>
     */
    private static function parseProtocolFile(string $path): array
    {
        $lines = VmFs::file($path, StdlibConstants::FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return [];
        }
        $entries = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            if (str_contains($line, '#')) {
                $line = trim(explode('#', $line, 2)[0]);
            }
            $parts = preg_split('/\s+/', $line, 3);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            if (!preg_match('/^(\d+)/', $parts[1], $m)) {
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
            $entries[] = [
                'name' => $parts[0],
                'number' => (int) $m[1],
                'aliases' => $aliases,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{name: string, port: int, protocol: string, aliases: list<string>}>
     */
    private static function parseServiceFile(string $path): array
    {
        $lines = VmFs::file($path, StdlibConstants::FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return [];
        }
        $entries = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            if (str_contains($line, '#')) {
                $line = trim(explode('#', $line, 2)[0]);
            }
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $name = array_shift($parts);
            $portProto = array_shift($parts);
            if (null === $name || null === $portProto || !preg_match('/^(\d+)\/([a-z0-9]+)$/i', $portProto, $m)) {
                continue;
            }
            $aliases = [];
            foreach ($parts as $alias) {
                if ('' !== $alias) {
                    $aliases[] = $alias;
                }
            }
            $entries[] = [
                'name' => $name,
                'port' => (int) $m[1],
                'protocol' => strtolower($m[2]),
                'aliases' => $aliases,
            ];
        }

        return $entries;
    }
}
