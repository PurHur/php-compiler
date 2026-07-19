<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ini_get()/ini_set() $option — Z_PARAM_STR.
 *
 * Null/int coerce on 8.2 reference profile (#18742, #17291); TypeError on 8.4+ (#17268, #20361)
 * and under caller strict_types (#16526). php-src: ext/standard/basic_functions.stub.php / ini.c.
 */
final class IniOptionArg
{
    public static function vmOption(Frame $frame, string $function): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, $function, 'option')->toString();
        }

        if (CompilerVersion::iniOptionRequiresStrictStringType()) {
            return VmString::requireStringBuiltinArg($frame->calledArgs[0], $function, 0, 'option');
        }

        $resolved = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return '';
        }

        return VmString::coerceStringBuiltinArg($frame->calledArgs[0], $function, 0, 'option');
    }

    public static function jitOption(Context $context, JITVariable $arg, string $function): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                $function,
                0,
                'option'
            );
        }

        if (CompilerVersion::iniOptionRequiresStrictStringType()) {
            return JitStringBuiltinArg::lowerRequiredString($context, $arg, $function, 0, 'option');
        }

        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitStringBuiltinArg::lower($context, $arg, $function, 0, 'option');
    }

    /**
     * Compile-time option that TypeErrors before any ini get/set call.
     *
     * Thin AOT must not reference empty `__compiler_ini_*` shells (#20361).
     * Boxed/runtime strings still go through {@see jitOption} + JitIni.
     */
    public static function jitOptionRejectsWithoutIniCall(Context $context, JITVariable $arg): bool
    {
        if (!$context->callerStrictTypes && !CompilerVersion::iniOptionRequiresStrictStringType()) {
            return false;
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            return false;
        }

        return JITVariable::TYPE_STRING !== $arg->type;
    }
}
