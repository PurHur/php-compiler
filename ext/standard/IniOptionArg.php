<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ini_get()/ini_set() $option — Z_PARAM_STR (null coerce, int TypeError; #17268, ext/standard/ini.c). */
final class IniOptionArg
{
    public static function vmOption(Frame $frame, string $function): string
    {
        InternalStrictArg::rejectNullString($frame->calledArgs[0], $function, 'option', 0, $frame);
        $resolved = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return '';
        }

        return VmString::requireStringBuiltinArg($frame->calledArgs[0], $function, 0, 'option');
    }

    public static function jitOption(Context $context, JITVariable $arg, string $function): Value
    {
        JitInternalStrictArg::rejectNullString($context, $arg, $function, 'option', 1);
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitStringBuiltinArg::lowerRequiredString($context, $arg, $function, 0, 'option');
    }
}
