<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * Per-stream built-in filter chains — php-src ext/standard/streams.c (#3283).
 *
 * PHP-in-PHP: no logic in runtime/*.c; apply on VmFs read/write paths.
 */
final class VmStreamFilterChain
{
    public const READ = 1;

    public const WRITE = 2;

    /** @var array<string, true> */
    private const BUILTIN_FILTERS = [
        'string.rot13' => true,
        'string.toupper' => true,
        'string.tolower' => true,
        'convert.base64-encode' => true,
        'convert.base64-decode' => true,
        'convert.quoted-printable-decode' => true,
        'convert.quoted-printable-encode' => true,
    ];

    /** @var array<int, array{stream: int, name: string, active: bool}> */
    private static array $filters = [];

    /** @var array<int, list<int>> */
    private static array $readChains = [];

    /** @var array<int, list<int>> */
    private static array $writeChains = [];

    private static int $nextFilterId = 1;

    public static function isBuiltinFilterName(string $name): bool
    {
        return isset(self::BUILTIN_FILTERS[strtolower($name)]);
    }

    public static function isKnownFilterName(string $name): bool
    {
        $name = strtolower($name);

        return self::isBuiltinFilterName($name)
            || \in_array($name, VmStreamFilters::registeredFilterNames(), true);
    }

    /**
     * @return int|false filter resource id
     */
    public static function append(
        int $streamHandle,
        string $filterName,
        int $readWrite,
        ?Frame $frame = null
    ) {
        return self::attach($streamHandle, $filterName, $readWrite, false, $frame);
    }

    /**
     * @return int|false filter resource id
     */
    public static function prepend(
        int $streamHandle,
        string $filterName,
        int $readWrite,
        ?Frame $frame = null
    ) {
        return self::attach($streamHandle, $filterName, $readWrite, true, $frame);
    }

    public static function isValidFilter(int $filterId): bool
    {
        return isset(self::$filters[$filterId]) && self::$filters[$filterId]['active'];
    }

    public static function filterHandle(Variable $target, int $filterId, ?\PHPCompiler\VM\Context $ctx = null): void
    {
        $target->streamFilterHandle($filterId, $ctx);
    }

    public static function getResourceType(int $filterId): ?string
    {
        return self::isValidFilter($filterId) ? 'stream filter' : null;
    }

    public static function remove(int $filterId): bool
    {
        if (!isset(self::$filters[$filterId]) || !self::$filters[$filterId]['active']) {
            return false;
        }
        $stream = self::$filters[$filterId]['stream'];
        self::$filters[$filterId]['active'] = false;
        self::$readChains[$stream] = array_values(array_filter(
            self::$readChains[$stream] ?? [],
            static fn (int $id): bool => $id !== $filterId
        ));
        self::$writeChains[$stream] = array_values(array_filter(
            self::$writeChains[$stream] ?? [],
            static fn (int $id): bool => $id !== $filterId
        ));

        return true;
    }

    public static function clearStream(int $streamHandle): void
    {
        unset(self::$readChains[$streamHandle], self::$writeChains[$streamHandle]);
        foreach (self::$filters as $id => $meta) {
            if ($meta['stream'] === $streamHandle) {
                unset(self::$filters[$id]);
            }
        }
    }

    public static function applyReadFilters(int $streamHandle, string $data): string
    {
        foreach (self::$readChains[$streamHandle] ?? [] as $filterId) {
            $data = self::applyFilter($filterId, $data);
        }

        return $data;
    }

    public static function applyWriteFilters(int $streamHandle, string $data): string
    {
        foreach (self::$writeChains[$streamHandle] ?? [] as $filterId) {
            $data = self::applyFilter($filterId, $data);
        }

        return $data;
    }

    /**
     * @return int|false
     */
    private static function attach(
        int $streamHandle,
        string $filterName,
        int $readWrite,
        bool $prepend,
        ?Frame $frame
    ) {
        $filterName = strtolower($filterName);
        if (!self::isKnownFilterName($filterName)) {
            self::warning(
                $frame,
                \sprintf('stream_filter_%s(): unable to locate filter "%s"', $prepend ? 'prepend' : 'append', $filterName)
            );

            return false;
        }
        if (!self::isBuiltinFilterName($filterName)) {
            self::warning(
                $frame,
                \sprintf('stream_filter_%s(): user filter "%s" is not implemented in this compiler build', $prepend ? 'prepend' : 'append', $filterName)
            );

            return false;
        }
        if (0 === ($readWrite & (self::READ | self::WRITE))) {
            $readWrite = self::READ;
        }

        $filterId = self::$nextFilterId++;
        self::$filters[$filterId] = [
            'stream' => $streamHandle,
            'name' => $filterName,
            'active' => true,
        ];

        if (0 !== ($readWrite & self::READ)) {
            if ($prepend) {
                self::$readChains[$streamHandle] ??= [];
                array_unshift(self::$readChains[$streamHandle], $filterId);
            } else {
                self::$readChains[$streamHandle] ??= [];
                self::$readChains[$streamHandle][] = $filterId;
            }
        }
        if (0 !== ($readWrite & self::WRITE)) {
            if ($prepend) {
                self::$writeChains[$streamHandle] ??= [];
                array_unshift(self::$writeChains[$streamHandle], $filterId);
            } else {
                self::$writeChains[$streamHandle] ??= [];
                self::$writeChains[$streamHandle][] = $filterId;
            }
        }

        return $filterId;
    }

    private static function applyFilter(int $filterId, string $data): string
    {
        if (!isset(self::$filters[$filterId]) || !self::$filters[$filterId]['active']) {
            return $data;
        }

        return self::transform(self::$filters[$filterId]['name'], $data);
    }

    private static function transform(string $filterName, string $data): string
    {
        return match ($filterName) {
            'string.rot13' => VmString::strRot13($data),
            'string.toupper' => VmString::asciiUpper($data),
            'string.tolower' => VmString::asciiLower($data),
            'convert.base64-encode' => \base64_encode($data),
            'convert.base64-decode' => (string) \base64_decode($data, true),
            'convert.quoted-printable-decode' => VmString::quoted_printable_decode($data),
            'convert.quoted-printable-encode' => VmString::quoted_printable_encode($data),
            default => $data,
        };
    }

    private static function warning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
