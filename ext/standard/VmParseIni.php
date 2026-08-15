<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for parse_ini_string() / parse_ini_file() (#3263).
 */
final class VmParseIni
{
    public static function parseString(
        string $ini,
        bool $processSections,
        int $scannerMode,
        ?Frame $frame = null,
        ?string $source = null
    ): array|false {
        if (!\in_array($scannerMode, [
            ParseIniEngine::SCANNER_NORMAL,
            ParseIniEngine::SCANNER_RAW,
            ParseIniEngine::SCANNER_TYPED,
        ], true)) {
            if (null !== $frame) {
                self::triggerInvalidScannerWarning($frame);
            }

            return false;
        }

        $parsed = ParseIniEngine::parse($ini, $processSections, $scannerMode);
        if (false === $parsed) {
            if (null !== $frame) {
                self::triggerSyntaxWarning($frame, $source);
            }

            return false;
        }

        return $parsed;
    }

    public static function parseFile(Frame $frame, string $filename, bool $processSections, int $scannerMode): array|false
    {
        if (!is_file($filename) || !is_readable($filename)) {
            self::triggerFileWarning($frame, $filename);

            return false;
        }
        $contents = VmFsReadNative::read($filename);
        if (false === $contents) {
            self::triggerFileWarning($frame, $filename);

            return false;
        }

        return self::parseString($contents, $processSections, $scannerMode, $frame, $filename);
    }

    public static function assignParsedResult(Variable $returnVar, array|false $parsed): void
    {
        if (false === $parsed) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->copyFrom(VmJson::import($parsed));
    }

    /**
     * Z_PARAM_BOOL $process_sections — caller strict_types → TypeError on null; else soft-null DEP+false (#31264).
     */
    public static function resolveProcessSections(Frame $frame, int $argIndex, string $fn): bool
    {
        return VmMath::parseBoolBuiltinArgForFrame(
            $frame,
            $argIndex,
            $fn,
            $argIndex + 1,
            'process_sections'
        );
    }

    /**
     * Z_PARAM_LONG $scanner_mode — caller strict_types → TypeError on null; else soft-null DEP+0 (#31264).
     */
    public static function resolveScannerMode(Frame $frame, int $argIndex, string $fn): int
    {
        return VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            $argIndex,
            $fn,
            $argIndex + 1,
            'scanner_mode'
        );
    }

    public static function triggerSyntaxWarning(Frame $frame, ?string $source = null): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $detail = ParseIniEngine::lastSyntaxError() ?? "unexpected '='";
        $line = ParseIniEngine::lastSyntaxLine() ?? 1;
        $frame->vmContext->errors->triggerError(
            'syntax error, '.$detail.' in '.($source ?? 'Unknown').' on line '.$line,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function triggerInvalidScannerWarning(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'Invalid scanner mode',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function triggerFileWarning(Frame $frame, string $filename): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf(
                'parse_ini_file(%s): Failed to open stream: No such file or directory',
                $filename
            ),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
