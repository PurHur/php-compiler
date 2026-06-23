<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for stream_filter_* ABI bridges (#9047, php-in-PHP).
 *
 * SSOT: {@see VmStreamFilterChain} / {@see VmStreamFilters} (ext/standard/streams.c).
 */
final class StreamFilterJitHelper
{
    public const HANDLE_BASE = 0x50000000;

    public static function append(int $streamHandle, string $filterName, int $readWrite): int
    {
        $filterId = VmStreamFilterChain::append($streamHandle, $filterName, $readWrite);
        if (false === $filterId) {
            return -1;
        }

        return self::HANDLE_BASE + (int) $filterId;
    }

    public static function prepend(int $streamHandle, string $filterName, int $readWrite): int
    {
        $filterId = VmStreamFilterChain::prepend($streamHandle, $filterName, $readWrite);
        if (false === $filterId) {
            return -1;
        }

        return self::HANDLE_BASE + (int) $filterId;
    }

    public static function remove(int $handle): int
    {
        $filterId = self::decodeFilterId($handle);
        if (null === $filterId) {
            return 0;
        }

        return VmStreamFilterChain::remove($filterId) ? 1 : 0;
    }

    public static function register(string $filterName, string $className): int
    {
        return VmStreamFilters::register($filterName, $className) ? 1 : 0;
    }

    public static function isValidHandle(int $handle): int
    {
        $filterId = self::decodeFilterId($handle);
        if (null === $filterId) {
            return 0;
        }

        return VmStreamFilterChain::isValidFilter($filterId) ? 1 : 0;
    }

    public static function applyWriteFilters(int $streamHandle, string $data): string
    {
        return VmStreamFilterChain::applyWriteFilters($streamHandle, $data);
    }

    public static function applyReadFilters(int $streamHandle, string $data): string
    {
        return VmStreamFilterChain::applyReadFilters($streamHandle, $data);
    }

    private static function decodeFilterId(int $handle): ?int
    {
        if ($handle < self::HANDLE_BASE) {
            return null;
        }
        $filterId = $handle - self::HANDLE_BASE;

        return $filterId > 0 ? $filterId : null;
    }
}
