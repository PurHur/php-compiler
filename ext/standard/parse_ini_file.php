<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * parse_ini_file() — INI file parser (ext/standard/basic_functions.c; issue #3263).
 */
final class parse_ini_file extends Internal
{
    public function __construct()
    {
        parent::__construct('parse_ini_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'parse_ini_file() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'parse_ini_file', 0, 'filename');
        VmString::rejectEmptyBuiltinStringArg($filename, 'parse_ini_file', 0, 'filename');
        $processSections = false;
        $scannerMode = ParseIniEngine::SCANNER_NORMAL;
        if ($argc >= 2) {
            $processSections = VmParseIni::resolveProcessSections($frame->calledArgs[1], 'parse_ini_file');
        }
        if (3 === $argc) {
            $scannerMode = VmParseIni::resolveScannerMode($frame->calledArgs[2], 'parse_ini_file');
        }
        VmParseIni::assignParsedResult(
            $frame->returnVar,
            VmParseIni::parseFile($frame, $filename, $processSections, $scannerMode)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('parse_ini_file() requires VM in this compiler build');
    }
}
