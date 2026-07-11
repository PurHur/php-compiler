<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;

/**
 * JIT/AOT `@` silence + error_reporting static storage (#9197, php-in-PHP).
 *
 * VM SSOT remains {@see ErrorReporter} per request context; compiled modules use this helper.
 * php-src: Zend/zend_execute.c — zend_begin_silence / zend_end_silence
 */
final class ErrorSilenceJitHelper
{
    private static int $errorReporting = ErrorReporter::DEFAULT_STARTUP_REPORTING;

    private static int $silenceDepth = 0;

    private static int $savedErrorReporting = 0;

    private static bool $displayErrors = false;

    public static function beginSilence(): void
    {
        if (0 === self::$silenceDepth) {
            self::$savedErrorReporting = self::$errorReporting;
            self::$errorReporting = 0;
        }
        ++self::$silenceDepth;
    }

    public static function endSilence(): void
    {
        if (self::$silenceDepth <= 0) {
            return;
        }
        --self::$silenceDepth;
        if (0 === self::$silenceDepth) {
            self::$errorReporting = self::$savedErrorReporting;
        }
    }

    public static function isErrorLevelEnabled(int $level): bool
    {
        return 0 !== (self::$errorReporting & $level);
    }

    public static function getDisplayErrors(): bool
    {
        return self::$displayErrors;
    }

    public static function setDisplayErrors(bool $display): void
    {
        self::$displayErrors = $display;
    }

    /** php-src php_error_cb: stderr when error_reporting includes level (#13542). */
    public static function shouldDisplayCliError(int $level): bool
    {
        return self::isErrorLevelEnabled($level);
    }

    public static function getErrorReporting(): int
    {
        return self::$errorReporting;
    }

    public static function setErrorReporting(int $level): void
    {
        self::$errorReporting = $level;
    }

    /** @return int previous mask when $hasNew; current mask when not */
    public static function errorReportingExchange(bool $hasNew, int $newLevel): int
    {
        $old = self::$errorReporting;
        if ($hasNew) {
            self::$errorReporting = $newLevel;
        }

        return $old;
    }

    public static function iniGetErrorReporting(): string
    {
        return (string) self::$errorReporting;
    }

    public static function iniRestoreErrorReporting(): void
    {
        self::$errorReporting = ErrorReporter::DEFAULT_STARTUP_REPORTING;
    }
}
