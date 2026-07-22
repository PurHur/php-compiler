<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\VM\Variable;

/**
 * bzerrno/bzerror/bzerrstr/bzflush — php-src ext/bz2/bz2.c (#22344).
 *
 * Error helpers require a bz2 stream ({@see VmBz2StreamPure}); bzflush aliases fflush
 * semantics for bz placeholders (always succeeds while open).
 */
final class VmBz2Error
{
    /** libbz2 BZ_OK */
    public const BZ_OK = 0;

    /** libbz2 BZ_SEQUENCE_ERROR */
    public const BZ_SEQUENCE_ERROR = -1;

    /** libbz2 BZ_PARAM_ERROR */
    public const BZ_PARAM_ERROR = -2;

    /** libbz2 BZ_IO_ERROR */
    public const BZ_IO_ERROR = -6;

    /**
     * @var array<int, string>
     */
    private const ERRSTR = [
        0 => 'OK',
        1 => 'RUN_OK',
        2 => 'FLUSH_OK',
        3 => 'FINISH_OK',
        4 => 'STREAM_END',
        -1 => 'SEQUENCE_ERROR',
        -2 => 'PARAM_ERROR',
        -3 => 'MEM_ERROR',
        -4 => 'DATA_ERROR',
        -5 => 'DATA_ERROR_MAGIC',
        -6 => 'IO_ERROR',
        -7 => 'UNEXPECTED_EOF',
        -8 => 'OUTBUFF_FULL',
        -9 => 'CONFIG_ERROR',
    ];

    public static function requireBz2Handle(Variable $v, string $functionName): int
    {
        $handle = VmStreamArg::requireStreamHandle($v, $functionName);
        if (!VmBz2Stream::isBzHandle($handle)) {
            throw new \TypeError(
                $functionName.'(): Argument #1 ($bz) must be a bz2 stream'
            );
        }

        return $handle;
    }

    public static function errno(int $handle): int
    {
        return VmBz2StreamPure::getErrno($handle);
    }

    public static function errstr(int $handle): string
    {
        return self::errstrFor(VmBz2StreamPure::getErrno($handle));
    }

    /**
     * @return array{errno: int, errstr: string}
     */
    public static function error(int $handle): array
    {
        $errno = VmBz2StreamPure::getErrno($handle);

        return [
            'errno' => $errno,
            'errstr' => self::errstrFor($errno),
        ];
    }

    public static function flush(int $handle): bool
    {
        if (VmBz2Stream::isBzHandle($handle)) {
            return true;
        }

        return \PHPCompiler\ext\standard\VmFs::fflush($handle);
    }

    public static function errstrFor(int $errno): string
    {
        return self::ERRSTR[$errno] ?? 'UNKNOWN';
    }
}
