<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;

/**
 * trigger_error() + undefined-array-key warnings for compiled JIT/AOT modules (#9293, php-in-PHP).
 *
 * VM SSOT remains {@see ErrorReporter}; compiled modules use this helper via thin LLVM bridges.
 * php-src: ext/standard/basic_functions.c, Zend/zend_execute.c
 */
final class TriggerErrorJitHelper
{
    public static function stderrPrintCliError(int $level, string $message, string $file, int $line): void
    {
        ErrorReporter::writeCliStderrLine($level, $message, '' !== $file ? $file : null, $line);
    }

    public static function undefinedArrayKey(string $key): void
    {
        self::recordAndMaybePrint(ErrorReporter::E_WARNING, 'Undefined array key "'.$key.'"');
    }

    public static function undefinedArrayKeyLong(int $key): void
    {
        self::recordAndMaybePrint(ErrorReporter::E_WARNING, "Undefined array key {$key}");
    }

    public static function warning(string $message): void
    {
        self::recordAndMaybePrint(ErrorReporter::E_WARNING, $message);
    }

    public static function nonVariableByRef(): void
    {
        self::recordAndMaybePrint(ErrorReporter::E_NOTICE, 'Only variables should be passed by reference');
    }

    /**
     * @return bool LLVM i1 ABI — whether stderr print is allowed (error_reporting / @ silence)
     */
    public static function shouldPrintTrigger(int $level): bool
    {
        return ErrorSilenceJitHelper::isErrorLevelEnabled($level);
    }

    public static function recordTriggerError(int $level, string $message, string $file, int $line): void
    {
        if ('' === $message) {
            return;
        }
        if ($line < 0) {
            $line = 0;
        }
        ErrorLastJitHelper::record($level, $message, $file, $line);
    }

    /**
     * Record last error and report whether dispatch/print should proceed.
     *
     * @return bool LLVM i1 ABI; bridge zext for __compiler_trigger_error control flow
     */
    public static function recordTrigger(int $level, string $message, string $file, int $line): bool
    {
        if ('' === $message) {
            return false;
        }
        if ($line < 0) {
            $line = 0;
        }
        ErrorLastJitHelper::record($level, $message, $file, $line);

        return ErrorSilenceJitHelper::isErrorLevelEnabled($level);
    }

    private static function recordAndMaybePrint(int $level, string $message): void
    {
        ErrorLastJitHelper::record($level, $message, '', 0);
        if (!ErrorSilenceJitHelper::isErrorLevelEnabled($level)) {
            return;
        }
        self::stderrPrintCliError($level, $message, '', 0);
    }
}
