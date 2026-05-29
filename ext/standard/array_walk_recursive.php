<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
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
 * JIT/AOT: compile-time string builtin callbacks; VM closure callbacks (#3086).
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
        if (3 === $argc) {
            throw new \LogicException(
                'array_walk_recursive() userdata is not supported in this compiler build'
            );
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $table = $array->toArray();
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array_walk_recursive() requires VM context in this compiler build'
                );
            }
            $closure = VmClosureCall::resolve($callback);
            $ok = self::walkClosure($frame->vmContext, $table, $closure);
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
        if (2 !== \count($args)) {
            throw new \LogicException(
                'array_walk_recursive() requires exactly two arguments in this compiler build'
            );
        }

        throw new \LogicException(
            'array_walk_recursive() not implemented for JIT in this build (#3111)'
        );
    }

    private static function walkString(HashTable $table, Internal $fn): bool
    {
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
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
        \PHPCompiler\VM\ClosureState $closure
    ): bool {
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                if (!self::walkClosure($context, $value->toArray(), $closure)) {
                    return false;
                }
                continue;
            }
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $result = VmClosureCall::invoke($context, $closure, $value, $keyCopy);
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
