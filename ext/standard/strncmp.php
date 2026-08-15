<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrncmp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strncmp() for two strings and an integer length (JIT via NCompareJitHelper PHP #15364).
 */
final class strncmp extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'strncmp', 3);
        $a = self::vmStringArg($frame, 0, 'string1');
        $b = self::vmStringArg($frame, 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31265).
        $len = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, 'strncmp', 3, 'length');
        $frame->returnVar->int(VmString::strncmp($a, $b, $len));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'strncmp', 3)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $i64 = $context->getTypeFromString('int64');
        // Soft-null outside strict_types; strict → TypeError (#31265).
        // Early return after compile-time null TypeError — open a dead insert block so the
        // call site can lower a discarded return without mid-block terminator (AOT verify;
        // peer dirname #31210 / intval #31227).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'strncmp', 3, 'length');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'strncmp_null_length_te_cont');

            return $i64->constInt(0, false);
        }
        StringStrncmp::ensureLinked($context);
        $left = self::jitStringArg($context, $args[0], 0, 'string1');
        $right = self::jitStringArg($context, $args[1], 1, 'string2');
        $length = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'strncmp', 3, 'length');

        return StringStrncmp::invoke($context, $left, $right, $length);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strncmp', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21317, peers strcmp #21190).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strncmp',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'strncmp',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'strncmp',
            $argIndex,
            $paramName
        );
    }
}
