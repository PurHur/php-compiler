<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native php://filter stream wrapper without host PHP @fopen (#4702, php_stream_filter.c).
 *
 * php-src: main/streams/php_stream_filter.c, ext/standard/php_fopen_wrapper.c
 */
final class VmPhpFilterStream
{
    private const PREFIX = 'php://filter/';

    /** @var list<string> */
    private const PARAM_KEYS = ['read=', 'write=', 'resource='];

    public static function isSupportedUri(string $uri): bool
    {
        return \str_starts_with($uri, self::PREFIX);
    }

    public static function open(string $uri, string $mode, ?\PHPCompiler\VM\Context $ctx = null): int|false
    {
        $parsed = self::parseSpec($uri);
        if (null === $parsed) {
            return false;
        }

        $handle = VmFs::fopen($parsed['resource'], $mode, $ctx);
        if (false === $handle) {
            return false;
        }

        foreach ($parsed['read'] as $filterName) {
            if (false === VmStreamFilterChain::append($handle, $filterName, VmStreamFilterChain::READ)) {
                VmFs::fclose($handle);

                return false;
            }
        }
        foreach ($parsed['write'] as $filterName) {
            if (false === VmStreamFilterChain::append($handle, $filterName, VmStreamFilterChain::WRITE)) {
                VmFs::fclose($handle);

                return false;
            }
        }

        return $handle;
    }

    /**
     * @return array{read: list<string>, write: list<string>, resource: string}|null
     */
    private static function parseSpec(string $uri): ?array
    {
        if (!self::isSupportedUri($uri)) {
            return null;
        }

        $spec = \substr($uri, \strlen(self::PREFIX));
        if ('' === $spec) {
            return null;
        }

        $read = [];
        $write = [];
        $resource = null;

        while ('' !== $spec) {
            if (\str_starts_with($spec, 'read=')) {
                [$value, $spec] = self::extractParamValue($spec, 'read=');
                $read = self::splitFilterNames($value);
            } elseif (\str_starts_with($spec, 'write=')) {
                [$value, $spec] = self::extractParamValue($spec, 'write=');
                $write = self::splitFilterNames($value);
            } elseif (\str_starts_with($spec, 'resource=')) {
                $resource = \substr($spec, \strlen('resource='));
                $spec = '';
            } else {
                return null;
            }
        }

        if (null === $resource || '' === $resource) {
            return null;
        }

        return [
            'read' => $read,
            'write' => $write,
            'resource' => $resource,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function extractParamValue(string $spec, string $key): array
    {
        $value = \substr($spec, \strlen($key));
        $next = self::nextParamOffset($value);
        if (null === $next) {
            return [$value, ''];
        }

        return [\substr($value, 0, $next), \substr($value, $next + 1)];
    }

    private static function nextParamOffset(string $value): ?int
    {
        $positions = [];
        foreach (self::PARAM_KEYS as $key) {
            $pos = \strpos($value, '/'.$key);
            if (false !== $pos) {
                $positions[] = $pos;
            }
        }
        if ([] === $positions) {
            return null;
        }

        return \min($positions);
    }

    /**
     * @return list<string>
     */
    private static function splitFilterNames(string $value): array
    {
        if ('' === $value) {
            return [];
        }

        return \array_values(\array_filter(
            \array_map('trim', \explode('|', $value)),
            static fn (string $name): bool => '' !== $name
        ));
    }
}
