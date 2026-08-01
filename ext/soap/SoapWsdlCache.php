<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * SoapClient/SoapServer WSDL cache — php-src ext/soap/php_sdl.c get_sdl (#26511).
 *
 * Caches WSDL XML (re-parse on hit). Disk files are `wsdl-{md5(uri)}.xml` under
 * soap.wsdl_cache_dir; memory cache is process-local with soap.wsdl_cache_limit.
 * No new runtime C.
 */
final class SoapWsdlCache
{
    /** @var array<string, string> */
    private static array $ini = [
        'soap.wsdl_cache_enabled' => '1',
        'soap.wsdl_cache_dir' => '/tmp',
        'soap.wsdl_cache_ttl' => '86400',
        'soap.wsdl_cache' => '1',
        'soap.wsdl_cache_limit' => '5',
    ];

    /** @var array<string, array{xml: string, time: int}> */
    private static array $memory = [];

    /** php-src soap.wsdl_cache_* INI keys (ext/soap/soap.c; #26511). */
    public static function isIniKey(string $key): bool
    {
        if (!SoapExtensionPolicy::advertisesExtension()) {
            return false;
        }

        return isset(self::$ini[\strtolower($key)]);
    }

    /**
     * @return string|false previous value (ini_set) or false on unknown key
     */
    public static function iniSet(string $option, string $value)
    {
        $key = \strtolower($option);
        if (!isset(self::$ini[$key])) {
            return false;
        }
        $old = self::$ini[$key];
        self::$ini[$key] = $value;

        return $old;
    }

    /** @return string|false */
    public static function iniGet(string $option)
    {
        $key = \strtolower($option);
        if (!isset(self::$ini[$key])) {
            return false;
        }

        return self::$ini[$key];
    }

    /**
     * Resolve effective cache_wsdl bitmask (php-src SoapClient ctor + SOAP_GLOBAL).
     *
     * @param array<string, mixed> $options
     */
    public static function resolveCacheMode(array $options): int
    {
        if (\array_key_exists('cache_wsdl', $options)) {
            return (int) $options['cache_wsdl'];
        }
        $enabled = self::iniBool('soap.wsdl_cache_enabled', true);
        if (!$enabled) {
            return SoapConstants::WSDL_CACHE_NONE;
        }

        return self::iniInt('soap.wsdl_cache', SoapConstants::WSDL_CACHE_DISK);
    }

    public static function get(string $uri, int $cacheMode): ?string
    {
        if (SoapConstants::WSDL_CACHE_NONE === $cacheMode || '' === $uri) {
            return null;
        }
        $now = \time();
        $ttl = self::iniInt('soap.wsdl_cache_ttl', 86400);
        if (($cacheMode & SoapConstants::WSDL_CACHE_MEMORY) !== 0) {
            $hit = self::$memory[$uri] ?? null;
            if (null !== $hit && ($ttl <= 0 || ($now - $hit['time']) <= $ttl)) {
                return $hit['xml'];
            }
            if (null !== $hit) {
                unset(self::$memory[$uri]);
            }
        }
        if (($cacheMode & SoapConstants::WSDL_CACHE_DISK) !== 0) {
            $path = self::diskPath($uri);
            if (null === $path || !\is_file($path)) {
                return null;
            }
            $mtime = @\filemtime($path);
            if (false === $mtime) {
                return null;
            }
            if ($ttl > 0 && ($now - $mtime) > $ttl) {
                @\unlink($path);

                return null;
            }
            $xml = @\file_get_contents($path);
            if (false === $xml || '' === $xml) {
                return null;
            }
            if (($cacheMode & SoapConstants::WSDL_CACHE_MEMORY) !== 0) {
                self::putMemory($uri, $xml, $mtime);
            }

            return $xml;
        }

        return null;
    }

    public static function put(string $uri, string $xml, int $cacheMode): void
    {
        if (SoapConstants::WSDL_CACHE_NONE === $cacheMode || '' === $uri || '' === $xml) {
            return;
        }
        $now = \time();
        if (($cacheMode & SoapConstants::WSDL_CACHE_MEMORY) !== 0) {
            self::putMemory($uri, $xml, $now);
        }
        if (($cacheMode & SoapConstants::WSDL_CACHE_DISK) !== 0) {
            $path = self::diskPath($uri);
            if (null === $path) {
                return;
            }
            $dir = \dirname($path);
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0777, true);
            }
            @\file_put_contents($path, $xml);
        }
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$memory = [];
        self::$ini = [
            'soap.wsdl_cache_enabled' => '1',
            'soap.wsdl_cache_dir' => '/tmp',
            'soap.wsdl_cache_ttl' => '86400',
            'soap.wsdl_cache' => '1',
            'soap.wsdl_cache_limit' => '5',
        ];
    }

    private static function putMemory(string $uri, string $xml, int $time): void
    {
        $limit = self::iniInt('soap.wsdl_cache_limit', 5);
        if ($limit > 0 && !isset(self::$memory[$uri]) && \count(self::$memory) >= $limit) {
            $oldestUri = null;
            $oldestTime = PHP_INT_MAX;
            foreach (self::$memory as $k => $entry) {
                if ($entry['time'] < $oldestTime) {
                    $oldestTime = $entry['time'];
                    $oldestUri = $k;
                }
            }
            if (null !== $oldestUri) {
                unset(self::$memory[$oldestUri]);
            }
        }
        self::$memory[$uri] = ['xml' => $xml, 'time' => $time];
    }

    private static function diskPath(string $uri): ?string
    {
        $dir = self::iniString('soap.wsdl_cache_dir', \sys_get_temp_dir());
        if ('' === $dir) {
            return null;
        }

        return \rtrim($dir, '/\\').'/wsdl-'.\md5($uri).'.xml';
    }

    private static function iniBool(string $name, bool $default): bool
    {
        $v = self::iniGet($name);
        if (false === $v || '' === $v) {
            return $default;
        }

        return !\in_array(\strtolower($v), ['0', 'off', 'false', ''], true);
    }

    private static function iniInt(string $name, int $default): int
    {
        $v = self::iniGet($name);
        if (false === $v || '' === $v) {
            return $default;
        }

        return (int) $v;
    }

    private static function iniString(string $name, string $default): string
    {
        $v = self::iniGet($name);
        if (false === $v || '' === $v) {
            return $default;
        }

        return $v;
    }
}
