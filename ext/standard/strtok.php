<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrtok;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strtok() — tokenize strings with static continuation state (php-src ext/standard/string.c; #3201).
 */
final class strtok extends Internal
{
    public function __construct()
    {
        parent::__construct('strtok');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strtok() accepts one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $str = VmString::coerceStrtokStringArg($frame->calledArgs[0]);
        $tok = null;
        if (2 === $argc) {
            $tok = VmString::coerceStrtokTokenArg($frame->calledArgs[1]);
        }
        $result = VmString::strtok($str, $tok);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strtok() accepts one or two arguments in this compiler build');
        }

        // Thin AOT: NestedJIT StrtokJitHelper segfaults (#26906). Fold literal
        // init+continuation sequences via VmString SSOT at compile time.
        $folded = self::tryFoldCompileTime($context, ...$args);
        if (null !== $folded) {
            return $folded;
        }

        if (1 === $argc) {
            StringStrtok::ensureLinked($context);

            return JitStrtok::tokenize(
                $context,
                null,
                JitStringBuiltinArg::lower($context, $args[0], 'strtok', 0, 'token', 'string', 'string')
            );
        }

        StringStrtok::ensureLinked($context);
        // Z_PARAM_STR_OR_NULL — preserve null so VmString::strtok one-arg mode matches php-src (#25171).
        $tok = JitStringBuiltinArg::lowerNullableString(
            $context,
            $args[1],
            'strtok',
            1,
            'token'
        );

        return JitStrtok::tokenize(
            $context,
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strtok', 0, 'string'),
            $tok
        );
    }

    /**
     * Emit a constant string|false when every strtok operand is a compile-time string (#26906).
     */
    private static function tryFoldCompileTime(Context $context, JITVariable ...$args): ?Value
    {
        $argc = \count($args);
        if (1 === $argc) {
            $token = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
            if (null === $token) {
                return null;
            }
            // One-arg continue: VmString::strtok($delimiter) with $tok=null.
            $result = VmString::strtok($token);
        } else {
            $str = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
            if (null === $str) {
                return null;
            }
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $tok = null;
            } else {
                $tok = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
                if (null === $tok) {
                    return null;
                }
            }
            $result = VmString::strtok($str, $tok);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $context->builder->load($context->constantStringFromString($result))
            );
        }

        return $ptr;
    }
}
