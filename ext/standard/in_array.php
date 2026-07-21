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
use PHPCompiler\JIT\JitArrayElem;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * in_array() for arrays of scalar values (subset of PHP; JIT via InArrayRuntime).
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
        $strict = false;
        if (3 === \count($frame->calledArgs)) {
            $strict = $frame->calledArgs[2]->resolveIndirect()->toBool();
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
        if (3 === \count($args)) {
            $strict = JitBoolArg::lower($context, $args[2], 'in_array() strict');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'in_array() needle');
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitArrayElem::requireArrayParam($context, $args[1], 'in_array', 2, 'haystack');

                return $context->constantFromBool(false);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'in_array', 1, 'haystack', 'array');

            return $context->constantFromBool(false);
        }
        JitArrayElem::requireArrayParam($context, $args[1], 'in_array', 2, 'haystack');

        return InArrayRuntime::inArray($context, $args[0], $args[1], $strict);
    }
}
