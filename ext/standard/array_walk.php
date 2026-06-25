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
 * JIT/AOT: compile-time string builtin callbacks (#1209) and closure callbacks with optional
 * $userdata (#4916). String callbacks with userdata remain VM-only (#3627).
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
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $subject->type && Variable::TYPE_OBJECT !== $subject->type) {
            throw new \TypeError(
                'array_walk(): Argument #1 ($array) must be of type array|object, '
                .self::valueTypeName($subject).' given'
            );
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $userdata = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        if (Variable::TYPE_ARRAY === $subject->type) {
            $array = $subject;
            $src = $array->toArray();
            $ok = self::walkArraySubject($frame, $array, $src, $callback, $userdata);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool($ok);
            }

            return;
        }
        $ok = self::walkObjectSubject($frame, $subject->toObject(), $callback, $userdata);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_walk() requires two or three arguments in this compiler build');
        }
        if (!ArrayMapCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        $userdata = 3 === $argc ? $args[2] : null;
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($args[1])) {
            return ArrayBuiltinHelper::walkInPlaceWithClosure($context, $args[0], $args[1], $userdata);
        }
        if (null !== $userdata) {
            throw new \LogicException(
                'array_walk() userdata is not supported for JIT/AOT string callbacks in this compiler build (#3627)'
            );
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_walk() callback');
        }

        return ArrayBuiltinHelper::walkInPlace($context, $args[0], $args[1]);
    }

    private static function walkArraySubject(
        Frame $frame,
        Variable $array,
        HashTable $src,
        Variable $callback,
        ?Variable $userdata
    ): bool {
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_walk() requires VM context in this compiler build');
            }

            return VmArrayWalk::walkArrayFlatClosure(
                $frame->vmContext,
                $src,
                VmClosureCall::resolve($callback),
                $userdata
            );
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                array_map::appendKeyedCopy($out, $key, $result);
            } else {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        $array->array($out);

        return true;
    }

    private static function walkObjectSubject(
        Frame $frame,
        \PHPCompiler\VM\ObjectEntry $object,
        Variable $callback,
        ?Variable $userdata
    ): bool {
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_walk() requires VM context in this compiler build');
            }

            return VmArrayWalk::walkObjectFlatClosure(
                $frame->vmContext,
                $object,
                $frame,
                VmClosureCall::resolve($callback),
                $userdata
            );
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        $vm = $frame->vmContext->runtime->vm();
        $iterator = new \PHPCompiler\VM\ObjectPropertyIterator($object, $vm, $frame);
        $iterator->reset();
        while ($iterator->valid()) {
            $propName = $iterator->currentKey()->toString();
            $value = $iterator->currentValue(true);
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                $object->getProperty($propName)->copyFrom($result);
            }
        }

        return true;
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
