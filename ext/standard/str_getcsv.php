<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_getcsv() — parse a CSV line from a string (subset of PHP; issue #2391).
 *
 * VM: {@see VmCsv::parseLine()}; JIT/AOT: {@see JitStrGetcsv} via __compiler_str_getcsv.
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
        $string = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $string->type) {
            throw new \LogicException('str_getcsv() argument #1 must be a string in this compiler build');
        }
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if ($argc >= 2) {
            $separator = VmReflection::stringArg($frame->calledArgs[1], 'str_getcsv() separator');
        }
        if ($argc >= 3) {
            $enclosure = VmReflection::stringArg($frame->calledArgs[2], 'str_getcsv() enclosure');
        }
        if ($argc >= 4) {
            $escape = VmReflection::stringArg($frame->calledArgs[3], 'str_getcsv() escape');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = VmCsv::parseLine($string->toString(), $separator, $enclosure, $escape);
        $frame->returnVar->array(VmFs::stringListToArray($row));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('str_getcsv() accepts one to four arguments in this compiler build');
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $input = JitStringArg::lower($context, $args[0], 'str_getcsv() argument #1');
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if ($argc >= 2) {
            $separator = JitStringArg::lower($context, $args[1], 'str_getcsv() separator');
        }
        if ($argc >= 3) {
            $enclosure = JitStringArg::lower($context, $args[2], 'str_getcsv() enclosure');
        }
        if ($argc >= 4) {
            $escape = JitStringArg::lower($context, $args[3], 'str_getcsv() escape');
        }

        return JitStrGetcsv::invoke($context, $input, $separator, $enclosure, $escape);
    }
}
