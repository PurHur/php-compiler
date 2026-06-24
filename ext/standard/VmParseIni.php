<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for parse_ini_string() / parse_ini_file() (#3263).
 */
final class VmParseIni
{
    public static function parseString(string $ini, bool $processSections, int $scannerMode, ?Frame $frame = null): array|false
    {
        $parsed = ParseIniEngine::parse($ini, $processSections, $scannerMode);
        if (false === $parsed) {
            if (null !== $frame) {
                self::triggerSyntaxWarning($frame);
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

        return self::parseString($contents, $processSections, $scannerMode);
    }

    public static function assignParsedResult(Variable $returnVar, array|false $parsed): void
    {
        if (false === $parsed) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->copyFrom(VmJson::import($parsed));
    }

    public static function resolveProcessSections(Variable $var, string $fn): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::boolTypeError($fn, 1, 'process_sections', $var));
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return false;
        }

        throw new \TypeError(self::boolTypeError($fn, 1, 'process_sections', $var));
    }

    public static function resolveScannerMode(Variable $var, string $fn): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::intTypeError($fn, 2, 'scanner_mode', $var));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return ParseIniEngine::SCANNER_NORMAL;
        }

        throw new \TypeError(self::intTypeError($fn, 2, 'scanner_mode', $var));
    }

    private static function boolTypeError(string $fn, int $argIndex, string $param, Variable $var): string
    {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type bool, %s given',
            $fn,
            $argIndex + 1,
            $param,
            EnumCaseSupport::typeNameForVariable($var)
        );
    }

    private static function intTypeError(string $fn, int $argIndex, string $param, Variable $var): string
    {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $fn,
            $argIndex + 1,
            $param,
            EnumCaseSupport::typeNameForVariable($var)
        );
    }

    public static function triggerSyntaxWarning(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $detail = ParseIniEngine::lastSyntaxError() ?? "unexpected '='";
        $frame->vmContext->errors->triggerError(
            'syntax error, '.$detail,
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
