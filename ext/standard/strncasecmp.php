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
use PHPCompiler\JIT\Builtin\StringStrncasecmp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strncasecmp() for two strings and an integer length (subset of PHP; JIT via CaseCompareJitHelper PHP #15225).
 */
final class strncasecmp extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'strncasecmp', 3);
        $a = self::vmStringArg($frame, 0, 'string1');
        $b = self::vmStringArg($frame, 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31265).
        $len = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, 'strncasecmp', 3, 'length');
        $frame->returnVar->int(VmString::strncasecmp($a, $b, $len));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'strncasecmp', 3)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $i64 = $context->getTypeFromString('int64');
        // Soft-null outside strict_types; strict → TypeError (#31265).
        // Early return after compile-time null TypeError — open a dead insert block so the
        // call site can lower a discarded return without mid-block terminator (AOT verify;
        // peer dirname #31210 / intval #31227 / strncmp #31265).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'strncasecmp', 3, 'length');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'strncasecmp_null_length_te_cont');

            return $i64->constInt(0, false);
        }
        StringStrncasecmp::ensureLinked($context);
        $p0 = $this->stringDataPtr($context, self::jitStringArg($context, $args[0], 0, 'string1'));
        $p1 = $this->stringDataPtr($context, self::jitStringArg($context, $args[1], 1, 'string2'));
        $length = $context->builder->zExt(
            $context->builder->trunc(
                JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'strncasecmp', 3, 'length'),
                $context->getTypeFromString('int32')
            ),
            $context->getTypeFromString('size_t')
        );
        $raw = $context->builder->call(
            $context->lookupFunction(\PHPCompiler\JIT\Builtin\StringCaseCompare::ABI_STRNCASECMP),
            $p0,
            $p1,
            $length
        );

        return $context->builder->sExt($raw, $i64);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strncasecmp', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21317, peers strcmp #21190).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strncasecmp',
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
                'strncasecmp',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'strncasecmp',
            $argIndex,
            $paramName
        );
    }
}
