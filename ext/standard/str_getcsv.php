<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * str_getcsv() — parse a CSV line from a string (subset of PHP; issue #2391).
 *
 * VM: {@see VmCsv::parseLine()}; JIT/AOT: {@see JitStrGetcsv} via StringStrGetcsv + CsvJitHelper (#9444).
 */
final class str_getcsv extends Internal
{
    public function __construct()
    {
        parent::__construct('str_getcsv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('str_getcsv() accepts one to four arguments in this compiler build');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'str_getcsv', 'string', 0, $frame);
        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'str_getcsv', 0, 'string');
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if ($argc >= 2) {
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'str_getcsv', 1, 'separator');
        }
        if ($argc >= 3) {
            $enclosure = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'str_getcsv', 2, 'enclosure');
        }
        if ($argc >= 4) {
            $escape = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'str_getcsv', 3, 'escape');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = VmCsv::parseLine($input, $separator, $enclosure, $escape);
        $frame->returnVar->array(VmFs::csvRowToArray($row));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('str_getcsv() accepts one to four arguments in this compiler build');
        }
        $strPtr = $context->getTypeFromString('__string__*');
        JitInternalStrictArg::rejectNullString($context, $args[0], 'str_getcsv', 'string', 1);
        $input = JitStringBuiltinArg::lower($context, $args[0], 'str_getcsv', 0, 'string');
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if ($argc >= 2) {
            $separator = JitStringBuiltinArg::lower($context, $args[1], 'str_getcsv', 1, 'separator');
        }
        if ($argc >= 3) {
            $enclosure = JitStringBuiltinArg::lower($context, $args[2], 'str_getcsv', 2, 'enclosure');
        }
        if ($argc >= 4) {
            $escape = JitStringBuiltinArg::lower($context, $args[3], 'str_getcsv', 3, 'escape');
        }

        return JitStrGetcsv::invoke($context, $input, $separator, $enclosure, $escape);
    }
}
