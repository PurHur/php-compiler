<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $subject = $frame->calledArgs[0]->resolveIndirect();
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $userdata = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        if (Variable::TYPE_ARRAY !== $subject->type && Variable::TYPE_OBJECT !== $subject->type) {
            throw new \TypeError(
                'array_walk_recursive(): Argument #1 ($array) must be of type array, '
                .self::valueTypeName($subject).' given'
            );
        }
        if (Variable::TYPE_ARRAY === $subject->type) {
            $subject->separateArrayForWrite();
            $table = $subject->toArray();
            $ok = $this->walkSubjectArray($frame, $table, $callback, $userdata);
        } else {
            $ok = $this->walkSubjectObject($frame, $subject->toObject(), $callback, $userdata);
        }
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

    private function walkSubjectArray(
        Frame $frame,
        \PHPCompiler\VM\HashTable $table,
        Variable $callback,
        ?Variable $userdata
    ): bool {
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array_walk_recursive() requires VM context in this compiler build'
                );
            }

            return VmArrayWalk::walkArrayRecursiveClosure(
                $frame->vmContext,
                $table,
                VmClosureCall::resolve($callback),
                $userdata
            );
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        [$internal, $userFn] = VmArrayWalkCallback::resolveString($frame, $callback->toString());
        if (null !== $userFn) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array_walk_recursive() requires VM context in this compiler build'
                );
            }

            return VmArrayWalk::walkArrayRecursiveUserFunction(
                $frame->vmContext,
                $table,
                $userFn,
                $userdata
            );
        }

        return VmArrayWalk::walkArrayRecursiveString($table, $internal);
    }

    private function walkSubjectObject(
        Frame $frame,
        \PHPCompiler\VM\ObjectEntry $object,
        Variable $callback,
        ?Variable $userdata
    ): bool {
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array_walk_recursive() requires VM context in this compiler build'
                );
            }

            return VmArrayWalk::walkObjectRecursiveClosure(
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
        [$internal, $userFn] = VmArrayWalkCallback::resolveString($frame, $callback->toString());
        if (null !== $userFn) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array_walk_recursive() requires VM context in this compiler build'
                );
            }

            return VmArrayWalk::walkObjectRecursiveUserFunction(
                $frame->vmContext,
                $object,
                $frame,
                $userFn,
                $userdata
            );
        }

        return VmArrayWalk::walkObjectRecursiveString($object, $frame, $internal);
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
