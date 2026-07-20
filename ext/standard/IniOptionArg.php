<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * ini_get()/ini_set() $option — Z_PARAM_STR.
 *
 * Soft-coerces null (and int/float/bool) with E_DEPRECATED on null across language
 * profiles including 8.4 (#21312, reverts #20361 TypeError; Zend basic_functions.stub.php).
 * Caller strict_types still rejects non-string (#16526). Enum cases TypeError (#5915).
 */
final class IniOptionArg
{
    public static function vmOption(Frame $frame, string $function): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, $function, 'option')->toString();
        }

        // Soft Z_PARAM_STR — Zend 8.4 DEP+coerce for null; int/float/bool coerce (#21312).
        return VmString::trimFamilyStringArgForFrame($frame, 0, $function, 0, 'option');
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

        // Soft Z_PARAM_STR — Zend 8.4 DEP+coerce (#21312).
        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, $function, 0, 'option');
    }

    /**
     * Compile-time option that TypeErrors before any ini get/set call.
     *
     * Thin AOT must not reference empty `__compiler_ini_*` shells (#20361).
     * Soft-null continues into JitIni (#21312). Only caller strict_types rejects
     * non-string compile-time operands without an ini call.
     */
    public static function jitOptionRejectsWithoutIniCall(Context $context, JITVariable $arg): bool
    {
        if (!$context->callerStrictTypes) {
            return false;
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            return false;
        }

        return JITVariable::TYPE_STRING !== $arg->type;
    }
}
