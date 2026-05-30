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
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_map() for list arrays with null or string builtin callbacks (subset of PHP).
 *
 * JIT/AOT: null, compile-time string builtins, and closure/arrow callbacks with native int/double
 * returns are lowered (issue #142). [class, method] and invokable object callables deferred (#1154).
 */
final class array_map extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('array_map() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $callback = $frame->calledArgs[0]->resolveIndirect();
        $array = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_map() second argument must be an array in this compiler build');
        }
        $src = $array->toArray();
        $out = new HashTable();
        if (Variable::TYPE_NULL === $callback->type) {
            self::copyKeyed($src, $out);
            $frame->returnVar->array($out);

            return;
        }
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_map() requires VM context in this compiler build');
            }
            $closure = VmClosureCall::resolve($callback);
            foreach ($src->iterateKeyed(true) as [$key, $value]) {
                $mapped = VmClosureCall::invokeOne($frame->vmContext, $closure, $value);
                self::appendKeyed($out, $key, $mapped);
            }
            $frame->returnVar->array($out);

            return;
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $mapped = VmInternalCall::invoke($fn, $value);
            self::appendKeyed($out, $key, $mapped);
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('array_map() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_HASHTABLE !== ($args[1]->type & ~JITVariable::IS_NATIVE_ARRAY)
            && !ArrayBuiltinHelper::isNativeArray($args[1]->type)) {
            throw new \LogicException('array_map() second argument must be an array in this compiler build');
        }

        if (!ArrayMapCallbackPolicy::isJitLowerable($args[0])) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        if (ArrayMapCallbackPolicy::isClosureJitLowerable($args[0])) {
            return ArrayBuiltinHelper::buildMapArrayWithClosure($context, $args[0], $args[1]);
        }

        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'array_map() callback');
        }

        return ArrayBuiltinHelper::buildMapArray($context, $args[0], $args[1]);
    }

    private static function copyKeyed(HashTable $src, HashTable $out): void
    {
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            self::appendKeyed($out, $key, $copy);
        }
    }

    public static function appendKeyedCopy(HashTable $out, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        self::appendKeyed($out, $key, $copy);
    }

    private static function appendKeyed(HashTable $out, Variable $key, Variable $value): void
    {
        if ($key->type === Variable::TYPE_INTEGER) {
            $out->append($value);

            return;
        }
        $out->add($key->toString(), $value);
    }
}
