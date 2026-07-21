<?php

declare(strict_types=1);

/**
 * vfprintf() — formatted write to stream with args array (ext/standard/formatted_print.c parity, #3752).
 *
 * Z_PARAM_STR $format: Zend 8.4 DEP+coerces null (#21514; sibling of #21234 printf/fprintf).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class vfprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('vfprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \LogicException('vfprintf() requires exactly three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $argsVar = $frame->calledArgs[2]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'vfprintf', 1);
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21514, formatted_print.c).
        $format = VmString::trimFamilyStringArgForFrame($frame, 1, 'vfprintf', 1, 'format');
        $written = VmVprintf::vfprintf(
            $handle,
            $format,
            $argsVar,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($written);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('vfprintf() requires exactly three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'vfprintf() stream'),
            $i64
        );
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21514, formatted_print.c).
        $nullFormat = JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'vfprintf', 1, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'vfprintf', 1, 'format');
        if ($nullFormat && $context->callerStrictTypes) {
            return $context->constantFromInteger(0, 'int64');
        }
        // Compile-time null→'' : empty format writes nothing (return 0). Avoids AOT
        // formatFromHashtable edge with constant-null format (#21514).
        if ($nullFormat) {
            JitVsprintfArrayArg::requireValues($context, $args[2], 'vfprintf', 3);

            return $context->constantFromInteger(0, 'int64');
        }
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
            \PHPCompiler\JIT\Builtin\StreamIo::ensureLinked($context);
        }
        $argsArray = JitVfprintf::loadArgsArray($context, $args[2], 'vfprintf');

        return JitVfprintf::invoke($context, $handle, $fmt, $argsArray);
    }
}
