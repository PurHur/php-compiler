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
use PHPCompiler\JIT\Builtin\ArrayMergeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_merge() for packed lists and string-key maps (subset of PHP; JIT via ArrayBuiltinHelper).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_merge) / Z_PARAM_VARIADIC('a')
 */
final class array_merge extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->newArray());

            return;
        }
        $first = VmArray::requireArrayArgNumForCaller($frame, $frame->calledArgs[0]->resolveIndirect(), 'array_merge', 1);
        if (1 === $argc) {
            $merged = VmArray::merge($first);
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->array($merged));

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $others[] = VmArray::requireArrayArgNumForCaller(
                $frame,
                $frame->calledArgs[$i]->resolveIndirect(),
                'array_merge',
                $i + 1
            );
        }
        $merged = VmArray::merge($first, ...$others);
        BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->array($merged));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        ExceptionBridge::ensureLinked($context);
        if (\count($args) < 1) {
            return HashTableHelper::emptyVariable($context)->value;
        }

        // php-src Z_PARAM_VARIADIC('a') — catchable TypeError under AOT try/catch (#27478; re-#21916).
        // Always via JitArrayElem → ExceptionBridge (not bare static-null early return).
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_merge() argument #'.((int) $i + 1));
            }
            JitArrayElem::requireArrayArgNum($context, $arg, 'array_merge', $i + 1);
        }

        return ArrayMergeRuntime::merge($context, ...$args);
    }
}
