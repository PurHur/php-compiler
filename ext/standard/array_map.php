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
use PHPCompiler\JIT\Builtin\ArrayMapRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_map() — null zip, string builtins, and closure callbacks (ext/standard/array.c; #4539).
 *
 * JIT/AOT: null, compile-time string builtins, and closure/arrow callbacks with native int/double
 * returns are lowered (issue #142). [class, method] and invokable object callables deferred (#1154).
 */
final class array_map extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'array_map() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $callback = $frame->calledArgs[0]->resolveIndirect();
        $arrays = [];
        for ($i = 1; $i < $argc; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \TypeError(sprintf(
                    'array_map(): Argument #%d ($array) must be of type array, %s given',
                    $i + 1,
                    self::typeLabel($arg)
                ));
            }
            $arrays[] = $arg->toArray();
        }
        if (1 === \count($arrays)) {
            $frame->returnVar->array(self::mapSingleArray($frame, $callback, $arrays[0]));

            return;
        }
        $frame->returnVar->array(self::mapMultipleArrays($frame, $callback, $arrays));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('array_map() expects at least 2 arguments in this compiler build');
        }
        $callback = $args[0];
        $arrays = \array_slice($args, 1);
        foreach ($arrays as $i => $array) {
            if (JITVariable::TYPE_HASHTABLE !== ($array->type & ~JITVariable::IS_NATIVE_ARRAY)
                && !ArrayBuiltinHelper::isNativeArray($array->type)
                && JITVariable::TYPE_VALUE !== $array->type) {
                throw new \LogicException(
                    'array_map() argument #'.($i + 2).' must be an array in this compiler build'
                );
            }
        }
        if (1 === \count($arrays)) {
            return self::lowerSingleArrayMap($context, $callback, $arrays[0]);
        }

        return ArrayBuiltinHelper::buildMapMultipleArrays($context, $callback, $arrays);
    }

    private static function lowerSingleArrayMap(Context $context, JITVariable $callback, JITVariable $array): Value
    {
        if (JITVariable::TYPE_STRING === $callback->type || JITVariable::TYPE_VALUE === $callback->type) {
            (new self())->jitString($context, $callback, 'array_map() callback');
        }

        return ArrayMapRuntime::mapSingle($context, $callback, $array);
    }

    private static function mapSingleArray(Frame $frame, Variable $callback, HashTable $src): HashTable
    {
        $out = new HashTable();
        if (Variable::TYPE_NULL === $callback->type) {
            self::copyKeyed($src, $out);

            return $out;
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

            return $out;
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $mapped = VmInternalCall::invoke($fn, $value);
            self::appendKeyed($out, $key, $mapped);
        }

        return $out;
    }

    /** @param list<HashTable> $arrays */
    private static function mapMultipleArrays(Frame $frame, Variable $callback, array $arrays): HashTable
    {
        $out = new HashTable();
        $first = $arrays[0];
        $destIdx = 0;
        foreach ($first->iterateKeyed(true) as [$key, $_value]) {
            $rowArgs = [];
            foreach ($arrays as $ht) {
                $rowArgs[] = self::valueAtKey($ht, $key);
            }
            if (Variable::TYPE_NULL === $callback->type) {
                $row = self::buildZipRow($rowArgs);
                $mapped = new Variable();
                $mapped->array($row);
                $out->addIndex($destIdx++, $mapped);

                continue;
            }
            if (VmClosureCall::isClosure($callback)) {
                if (null === $frame->vmContext) {
                    throw new \LogicException('array_map() requires VM context in this compiler build');
                }
                $closure = VmClosureCall::resolve($callback);
                $mapped = VmClosureCall::invoke($frame->vmContext, $closure, ...$rowArgs);
                $out->addIndex($destIdx++, $mapped);

                continue;
            }
            throw new \LogicException(
                'array_map() with multiple arrays requires a null or closure callback in this compiler build'
            );
        }

        return $out;
    }

    /** @param list<Variable> $values */
    private static function buildZipRow(array $values): HashTable
    {
        $row = new HashTable();
        $idx = 0;
        foreach ($values as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $row->addIndex($idx++, $copy);
        }

        return $row;
    }

    private static function valueAtKey(HashTable $ht, Variable $key): Variable
    {
        $found = null;
        if (Variable::TYPE_INTEGER === $key->type) {
            $found = $ht->findIndex($key->toInt());
        } elseif (Variable::TYPE_STRING === $key->type) {
            $found = $ht->find($key->toString());
        }
        $result = new Variable();
        if (null === $found) {
            $result->reset();
            $result->type = Variable::TYPE_NULL;

            return $result;
        }
        $resolved = $found->resolveIndirect();
        if ($resolved->isUndefined()) {
            $result->reset();
            $result->type = Variable::TYPE_NULL;

            return $result;
        }
        $result->copyFrom($found);

        return $result;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
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
            $out->addIndex($key->toInt(), $value);

            return;
        }
        $out->add($key->toString(), $value);
    }
}
