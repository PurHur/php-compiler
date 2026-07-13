<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ini_get()/ini_set() $option — Z_PARAM_STR (null coerce; int coerce on 8.2, TypeError on 8.4+; #17268, #17291, ext/standard/ini.c). */
final class IniOptionArg
{
    public static function vmOption(Frame $frame, string $function): string
    {
        $resolved = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return '';
        }

        if (CompilerVersion::iniOptionRequiresStrictStringType()) {
            return VmString::requireStringBuiltinArg($frame->calledArgs[0], $function, 0, 'option');
        }

        return VmString::coerceStringBuiltinArg($frame->calledArgs[0], $function, 0, 'option');
    }

    public static function jitOption(Context $context, JITVariable $arg, string $function): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        if (CompilerVersion::iniOptionRequiresStrictStringType()) {
            return JitStringBuiltinArg::lowerRequiredString($context, $arg, $function, 0, 'option');
        }

        return JitStringBuiltinArg::lower($context, $arg, $function, 0, 'option');
    }
}
