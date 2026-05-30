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
 * array_walk() — in-place walk with string builtin or closure callbacks (issues #1209, #3627).
 *
 * php-src: ext/standard/array.c — php_array_walk()
 *
 * JIT/AOT: compile-time string builtin callbacks (subset; #1209). Closure callbacks and
 * optional $userdata are VM-only (#3627).
 */
final class array_walk extends Internal
{
    public function __construct()
    {
        parent::__construct('array_walk');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_walk() requires two or three arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_walk() first argument must be an array in this compiler build');
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $userdata = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        $src = $array->toArray();
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_walk() requires VM context in this compiler build');
            }
            $closure = VmClosureCall::resolve($callback);
            $ok = self::walkClosure($frame->vmContext, $src, $closure, $userdata);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool($ok);
            }

            return;
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                array_map::appendKeyedCopy($out, $key, $result);
            } else {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        $array->array($out);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            if (3 === \count($args)) {
                throw new \LogicException(
                    'array_walk() userdata is not supported for JIT/AOT in this compiler build (#3627)'
                );
            }
            throw new \LogicException('array_walk() requires exactly two arguments in this compiler build');
        }
        if (!ArrayMapCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_walk() callback');
        }

        return ArrayBuiltinHelper::walkInPlace($context, $args[0], $args[1]);
    }

    private static function walkClosure(
        \PHPCompiler\VM\Context $context,
        HashTable $table,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
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
}
