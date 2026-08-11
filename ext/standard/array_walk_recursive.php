<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Builtin\ArrayWalkRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
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
        VmArraySortCallback::requireCallback($frame->calledArgs[1], 'array_walk_recursive', 2);
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $userdata = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        if (Variable::TYPE_ARRAY !== $subject->type && Variable::TYPE_OBJECT !== $subject->type) {
            throw new \TypeError(
                'array_walk_recursive(): Argument #1 ($array) must be of type array, '
                .EnumCaseSupport::typeNameForTypeErrorActual($subject).' given'
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
        // Zend TypeError text is "array" only (php-src ext/standard/array.c) — #19836 / #27632.
        $badSubject = self::jitKnownBadArraySubjectLabel($args[0]);
        if (null !== $badSubject) {
            throw new \TypeError(
                'array_walk_recursive(): Argument #1 ($array) must be of type array, '
                .$badSubject.' given'
            );
        }
        // Runtime-null / boxed non-array under thin AOT — catchable TypeError, not segfault (#27632).
        if (JITVariable::TYPE_VALUE === $args[0]->type || \PHPCompiler\JIT\JitValueBox::isValueOperand($args[0])) {
            JitArrayElem::requireArrayParam($context, $args[0], 'array_walk_recursive', 1, 'array');
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            throw new \TypeError(
                'array_walk_recursive(): Argument #2 ($callback) must be a valid callback, no array or string given'
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
            return ArrayWalkRuntime::walkRecursiveInPlaceWithClosure($context, $args[0], $args[1], null);
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_walk_recursive() callback');
        }

        return ArrayWalkRuntime::walkRecursiveInPlaceWithStringBuiltin($context, $args[0], $args[1]);
    }

    private static function jitKnownBadArraySubjectLabel(JITVariable $subject): ?string
    {
        if (JITVariable::TYPE_NULL === $subject->type || ($subject->isNullConstant ?? false)) {
            return 'null';
        }
        switch ($subject->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return self::jitNativeBoolLiteralLabel($subject);
            case JITVariable::TYPE_STRING:
                return 'string';
            default:
                return null;
        }
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
        if (VmArrayWalk::isGeneralVmCallable($callback)) {
            return VmArrayWalk::walkArrayRecursiveVmCallable(
                $frame,
                $table,
                $callback,
                $userdata,
                'array_walk_recursive'
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

    /** zend_zval_value_name — bool actuals print true/false (#30144). */
    private static function jitNativeBoolLiteralLabel(JITVariable $subject): string
    {
        $value = $subject->value;
        if (\method_exists($value, 'isConstant') && $value->isConstant()
            && \method_exists($value, 'getConstantValue')
        ) {
            return 0 !== (int) $value->getConstantValue() ? 'true' : 'false';
        }

        return 'bool';
    }
}
