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
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_filter() with default falsy removal or string builtin callback (subset of PHP).
 */
final class array_filter extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_filter() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_filter() first argument must be an array in this compiler build');
        }
        $src = $array->toArray();
        $out = new HashTable();
        if (1 === $argc) {
            foreach ($src->iterateKeyed(true) as [$key, $value]) {
                if (boolval::isTruthy($value)) {
                    array_map::appendKeyedCopy($out, $key, $value);
                }
            }
            $frame->returnVar->array($out);

            return;
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \LogicException(
                'array_filter() callback must be a string builtin name in this compiler build'
            );
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $keep = VmInternalCall::invoke($fn, $value);
            if (boolval::isTruthy($keep)) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_filter() requires one or two arguments in this compiler build');
        }
        if (2 === $argc) {
            throw new \LogicException(
                'array_filter() with a callback is not supported by the JIT compiler in this build'
            );
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_filter() argument #'.((int) $i + 1));
            }
        }
        return ArrayBuiltinHelper::buildFilterArray($context, $args[0]);
    }
}
