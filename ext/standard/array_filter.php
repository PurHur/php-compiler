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
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_filter() with default falsy removal or string builtin / closure callbacks.
 *
 * php-src: ext/standard/array.c — php_array_filter(), ARRAY_FILTER_USE_* modes (#4243).
 */
final class array_filter extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('array_filter() requires one to three arguments in this compiler build');
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
            self::filterDefault($src, $out);
            $frame->returnVar->array($out);

            return;
        }
        $mode = 0;
        if (3 === $argc) {
            $mode = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            self::filterDefault($src, $out);
            $frame->returnVar->array($out);

            return;
        }
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_filter() requires VM context in this compiler build');
            }
            $closure = VmClosureCall::resolve($callback);
            foreach ($src->iterateKeyed(true) as [$key, $value]) {
                $keep = self::invokeClosure($frame->vmContext, $closure, $mode, $key, $value);
                if (boolval::isTruthy($keep)) {
                    array_map::appendKeyedCopy($out, $key, $value);
                }
            }
            $frame->returnVar->array($out);

            return;
        }
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \LogicException(
                'array_filter() callback must be a string builtin name or closure in this compiler build'
            );
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $keep = self::invokeInternal($fn, $mode, $key, $value);
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
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('array_filter() requires one to three arguments in this compiler build');
        }
        if ($argc >= 2) {
            throw new \LogicException(
                'array_filter() with a callback is not supported by the JIT compiler in this build'
            );
        }

        return ArrayBuiltinHelper::buildFilterArray($context, $args[0]);
    }

    private static function filterDefault(HashTable $src, HashTable $out): void
    {
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            if (boolval::isTruthy($value)) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
    }

    private static function invokeClosure(
        VmContext $context,
        \PHPCompiler\VM\ClosureState $closure,
        int $mode,
        Variable $key,
        Variable $value,
    ): Variable {
        return match ($mode) {
            StdlibConstants::ARRAY_FILTER_USE_KEY => VmClosureCall::invokeOne($context, $closure, $key),
            StdlibConstants::ARRAY_FILTER_USE_BOTH => VmClosureCall::invoke($context, $closure, $value, $key),
            default => VmClosureCall::invokeOne($context, $closure, $value),
        };
    }

    private static function invokeInternal(Internal $fn, int $mode, Variable $key, Variable $value): Variable
    {
        return match ($mode) {
            StdlibConstants::ARRAY_FILTER_USE_KEY => VmInternalCall::invoke($fn, $key),
            StdlibConstants::ARRAY_FILTER_USE_BOTH => VmInternalCall::invoke($fn, $value, $key),
            default => VmInternalCall::invoke($fn, $value),
        };
    }
}
