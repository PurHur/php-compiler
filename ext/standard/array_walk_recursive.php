<?php

declare(strict_types=1);

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
 * array_walk_recursive() — depth-first walk invoking callback on leaf scalars (issue #3111).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_walk_recursive)
 *
 * JIT/AOT: compile-time string builtin callbacks (#3111); closure/arrow callbacks (#4039).
 * Optional $userdata is VM-only for closure callbacks (#4913, mirrors #3627).
 */
final class array_walk_recursive extends Internal
{
    public function __construct()
    {
        parent::__construct('array_walk_recursive');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException(
                'array_walk_recursive() requires two or three arguments in this compiler build'
            );
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \TypeError(
                'array_walk_recursive(): Argument #1 ($array) must be of type array, '
                .self::valueTypeName($array).' given'
            );
        }
        $array->separateArrayForWrite();
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $userdata = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        $table = $array->toArray();
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array_walk_recursive() requires VM context in this compiler build'
                );
            }
            $closure = VmClosureCall::resolve($callback);
            $ok = self::walkClosure($frame->vmContext, $table, $closure, $userdata);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool($ok);
            }

            return;
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        $ok = self::walkString($table, $fn);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException(
                'array_walk_recursive() requires two or three arguments in this compiler build'
            );
        }
        if (3 === $argc) {
            throw new \LogicException(
                'array_walk_recursive() userdata is not supported for JIT/AOT in this compiler build (#4913)'
            );
        }
        if (!ArrayMapCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($args[1])) {
            return ArrayBuiltinHelper::walkRecursiveInPlaceWithClosure($context, $args[0], $args[1], null);
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_walk_recursive() callback');
        }

        return ArrayBuiltinHelper::walkRecursiveInPlace($context, $args[0], $args[1]);
    }

    private static function walkString(HashTable $table, Internal $fn): bool
    {
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkString($value->toArray(), $fn)) {
                    return false;
                }
                continue;
            }
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                self::replaceAtKey($table, $key, $result);
            }
        }

        return true;
    }

    private static function walkClosure(
        \PHPCompiler\VM\Context $context,
        HashTable $table,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkClosure($context, $value->toArray(), $closure, $userdata)) {
                    return false;
                }
                continue;
            }
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy, $userdataCopy);
            } else {
                $result = VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                self::replaceAtKey($table, $key, $result);
            }
        }

        return true;
    }

    private static function replaceAtKey(HashTable $table, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        if (Variable::TYPE_INTEGER === $key->type) {
            $table->updateIndex($key->toInt(), $copy);
        } else {
            $table->update($key->toString(), $copy);
        }
    }

    private static function valueTypeName(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
