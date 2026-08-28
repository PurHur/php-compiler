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
    /**
     * Zend startup error_reporting for compiled modules — bake the mask; do not call
     * {@see ErrorReporter::defaultStartupReporting()} at runtime (#35563). That path reaches
     * {@see \PHPCompiler\CompilerVersion::languageProfileVersion()} which is null on thin AOT,
     * so eAll() / defaultStartupReporting() collapse to null and silence every stderr gate.
     */
    private const COMPILED_DEFAULT_ERROR_REPORTING = ErrorReporter::E_ALL_LEGACY;

    private static ?int $errorReporting = null;

    private static int $silenceDepth = 0;

    private static int $savedErrorReporting = 0;

    private static bool $displayErrors = false;

    /**
     * NestedJIT under thin AOT does not apply PHP static property defaults (BSS-zero) (#33059, #35563).
     * Nullable {@see $errorReporting} reads as 0, so {@code ??=} never seeds Zend startup mask.
     */
    private static bool $compiledModuleDefaultsSeeded = false;

    /**
     * Seed compiled-module error_reporting before first gate — trigger_error may run before ini_get (#35563).
     *
     * php-src: main/php_ini.c PG(error_reporting) startup default; VM uses {@see ErrorReporter}.
     */
    public static function ensureCompiledModuleDefaults(): void
    {
        if (self::$compiledModuleDefaultsSeeded) {
            return;
        }
        self::$compiledModuleDefaultsSeeded = true;
        self::$errorReporting = self::COMPILED_DEFAULT_ERROR_REPORTING;
    }

    private static function currentErrorReporting(): int
    {
        self::ensureCompiledModuleDefaults();

        return self::$errorReporting ?? self::COMPILED_DEFAULT_ERROR_REPORTING;
    }

    public static function beginSilence(): void
    {
        if (0 === self::$silenceDepth) {
            self::$savedErrorReporting = self::currentErrorReporting();
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
        return 0 !== (self::currentErrorReporting() & $level);
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
        return self::currentErrorReporting();
    }

    public static function setErrorReporting(int $level): void
    {
        self::ensureCompiledModuleDefaults();
        self::$errorReporting = $level;
    }

    /** @return int previous mask when $hasNew; current mask when not */
    public static function errorReportingExchange(bool $hasNew, int $newLevel): int
    {
        $old = self::currentErrorReporting();
        if ($hasNew) {
            self::$errorReporting = $newLevel;
        }

        return $old;
    }

    public static function iniGetErrorReporting(): string
    {
        return (string) self::currentErrorReporting();
    }

    public static function iniRestoreErrorReporting(): void
    {
        self::$compiledModuleDefaultsSeeded = true;
        self::$errorReporting = self::COMPILED_DEFAULT_ERROR_REPORTING;
    }
}
