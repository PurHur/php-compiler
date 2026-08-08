<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\VmActiveContextJitHelper;

/**
 * libxml internal-error flag + scalar ring for VM + NestedJIT AOT (#28659, #29161, php-in-PHP).
 *
 * Thin standalone AOT keeps a scalar ring in LLVM globals; {@see JitLibxmlGetErrors} builds
 * LibXMLError[] in a dedicated ABI fn (hrtime_pair peer). NestedJIT ObjectEntry/HashTable aborts.
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_use_internal_errors / clear / get_errors / get_last_error)
 */
final class LibxmlInternalErrorsJitHelper
{
    private static bool $useInternalErrors = false;

    /** @var list<array{level: int, code: int, column: int, message: string, file: string, line: int}> */
    private static array $errors = [];

    /**
     * @param bool $hasNew when false (omitted/null arg), only return the previous flag
     *
     * @return bool previous use_internal_errors flag
     */
    public static function exchange(bool $hasNew, bool $newValue): bool
    {
        $previous = self::$useInternalErrors;
        if ($hasNew) {
            self::$useInternalErrors = $newValue;
        }

        return $previous;
    }

    public static function using(): bool
    {
        return self::$useInternalErrors;
    }

    public static function clear(): void
    {
        self::$errors = [];
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    public static function record(array $record): void
    {
        self::$errors[] = $record;
    }

    /**
     * @return list<array{level: int, code: int, column: int, message: string, file: string, line: int}>
     */
    public static function records(): array
    {
        return self::$errors;
    }

    public static function countErrors(): int
    {
        return \count(self::$errors);
    }

    public static function levelAt(int $i): int
    {
        return (int) (self::$errors[$i]['level'] ?? 0);
    }

    public static function codeAt(int $i): int
    {
        return (int) (self::$errors[$i]['code'] ?? 0);
    }

    public static function columnAt(int $i): int
    {
        return (int) (self::$errors[$i]['column'] ?? 0);
    }

    public static function messageAt(int $i): string
    {
        return (string) (self::$errors[$i]['message'] ?? '');
    }

    public static function fileAt(int $i): string
    {
        return (string) (self::$errors[$i]['file'] ?? '');
    }

    public static function lineAt(int $i): int
    {
        return (int) (self::$errors[$i]['line'] ?? 0);
    }

    /** JIT/embed NestedJIT — build LibXMLError[] via VmLibxml (#29161). */
    public static function getErrorsHt(): HashTable
    {
        $ctx = VmActiveContextJitHelper::resolve();
        VmLibxml::registerClass($ctx);

        return VmLibxml::getErrors($ctx);
    }

    /**
     * JIT/embed NestedJIT — last LibXMLError or null (caller boxes as false) (#29161).
     *
     * @return \PHPCompiler\VM\ObjectEntry|null
     */
    public static function getLastErrorObject()
    {
        $ctx = VmActiveContextJitHelper::resolve();
        VmLibxml::registerClass($ctx);
        $errors = self::$errors;
        if ([] === $errors) {
            return null;
        }

        return VmLibxml::createErrorObject($ctx, $errors[\count($errors) - 1])->toObject();
    }

    /**
     * Append one libxml error from scalar NestedJIT/AOT args (#29161).
     */
    public static function recordScalars(
        int $level,
        int $code,
        int $column,
        string $message,
        string $file,
        int $line
    ): void {
        self::$errors[] = [
            'level' => $level,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ];
    }
}
