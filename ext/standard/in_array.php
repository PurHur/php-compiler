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
use PHPCompiler\JIT\Builtin\InArrayRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * in_array() for arrays of scalar values (subset of PHP; JIT via InArrayRuntime).
 *
 * php-src: ext/standard/array.stub.php / array.c — PHP_FUNCTION(in_array)
 */
final class in_array extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'in_array() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'in_array() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $needle = $frame->calledArgs[0]->resolveIndirect();
        $haystack = VmArray::requireArrayParamForCaller(
            $frame,
            $frame->calledArgs[1],
            'in_array',
            2,
            'haystack'
        );
        // Z_PARAM_BOOL $strict — strict_types TypeError; else null→false + E_DEPRECATED (#29866).
        $strict = false;
        if (3 === $argc) {
            $strict = VmMath::parseBoolBuiltinArgForFrame($frame, 2, 'in_array', 3, 'strict');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmArray::contains($needle, $haystack, $strict));
    }

    public static function looseEquals(Variable $left, Variable $right, ?\PHPCompiler\VM $vm = null): bool
    {
        return $left->resolveIndirect()->equals($right->resolveIndirect(), $vm);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'in_array() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'in_array() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $strict = $context->constantFromBool(false);
        if (3 === $argc) {
            // Compile-time null under strict: catchable TypeError then stop IR (peer substr_compare #29756).
            if ($context->callerStrictTypes && self::isCompileTimeNull($args[2])) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'in_array(): Argument #3 ($strict) must be of type bool, null given'
                );

                return $context->constantFromBool(false);
            }
            $strict = JitBoolArg::lowerCoerceZParamBool($context, $args[2], 'in_array', 'strict', 3);
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'in_array() needle');
        }
        // php-src 8.0+: Z_PARAM_ARRAY — always TypeError on null (#21916, re-#21771).
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            JitArrayElem::requireArrayParam($context, $args[1], 'in_array', 2, 'haystack');

            return $context->constantFromBool(false);
        }
        JitArrayElem::requireArrayParam($context, $args[1], 'in_array', 2, 'haystack');

        return InArrayRuntime::inArray($context, $args[0], $args[1], $strict);
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
