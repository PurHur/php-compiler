<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
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
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException('str_getcsv() requires at least a string argument in this compiler build');
        }
        foreach (\array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 3) {
                throw new \ArgumentCountError(\sprintf(
                    'str_getcsv() expects at most 4 arguments, %d given',
                    $idx + 1
                ));
            }
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'str_getcsv', 'string', 0, $frame);
        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'str_getcsv', 0, 'string');
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if (isset($frame->calledArgs[1])) {
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'str_getcsv', 1, 'separator');
        }
        if (isset($frame->calledArgs[2])) {
            $enclosure = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'str_getcsv', 2, 'enclosure');
        }
        if (isset($frame->calledArgs[3])) {
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
        if (\count($args) < 1) {
            throw new \LogicException('str_getcsv() requires at least a string argument in this compiler build');
        }
        if (\count($args) > 4) {
            throw new \LogicException('str_getcsv() expects at most 4 arguments');
        }
        $strPtr = $context->getTypeFromString('__string__*');
        JitInternalStrictArg::rejectNullString($context, $args[0], 'str_getcsv', 'string', 1);
        $input = JitStringBuiltinArg::lower($context, $args[0], 'str_getcsv', 0, 'string');
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $separator = JitStringBuiltinArg::lower($context, $args[1], 'str_getcsv', 1, 'separator');
        }
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $enclosure = JitStringBuiltinArg::lower($context, $args[2], 'str_getcsv', 2, 'enclosure');
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $escape = JitStringBuiltinArg::lower($context, $args[3], 'str_getcsv', 3, 'escape');
        }

        return JitStrGetcsv::invoke($context, $input, $separator, $enclosure, $escape);
    }
}
