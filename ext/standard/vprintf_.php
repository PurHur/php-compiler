<?php

declare(strict_types=1);

/**
 * vprintf() — formatted write to stdout with args array (ext/standard/formatted_print.c parity, #3752).
 *
 * Z_PARAM_STR $format: Zend 8.4 DEP+coerces null (#21514; sibling of #21234 printf/fprintf).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class vprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('vprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'vprintf() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21514, formatted_print.c).
        $format = VmString::trimFamilyStringArgForFrame($frame, 0, 'vprintf', 0, 'format');
        $argsVar = $frame->calledArgs[1]->resolveIndirect();
        $written = VmVprintf::vprintf($format, $argsVar, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($written);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'vprintf() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $i64 = $context->getTypeFromString('int64');
        $stdout = $i64->constInt(1, false);
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21514, formatted_print.c).
        $nullFormat = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'vprintf', 0, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'vprintf', 0, 'format');
        if ($nullFormat && $context->callerStrictTypes) {
            return $context->constantFromInteger(0, 'int64');
        }
        // Compile-time null→'' : empty format writes nothing (return 0). Avoids AOT
        // formatFromHashtable edge with constant-null format (#21514).
        if ($nullFormat) {
            JitVsprintfArrayArg::requireValues($context, $args[1], 'vprintf', 2);

            return $context->constantFromInteger(0, 'int64');
        }
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
            \PHPCompiler\JIT\Builtin\StreamIo::ensureLinked($context);
        }
        $argsArray = JitVfprintf::loadArgsArray($context, $args[1]);

        return JitVfprintf::invoke($context, $stdout, $fmt, $argsArray);
    }
}
