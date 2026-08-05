<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Builtin\ArrayWalkRuntime;
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
        // Objects are accepted (php_array_walk), but Zend TypeError text is "array" only
        // (php-src ext/standard/array.c / Zend 8.2+ observable message) — #19836.
        if (Variable::TYPE_ARRAY !== $subject->type && Variable::TYPE_OBJECT !== $subject->type) {
            throw new \TypeError(
                'array_walk(): Argument #1 ($array) must be of type array, '
                .self::valueTypeName($subject).' given'
            );
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        VmArraySortCallback::requireCallback($frame->calledArgs[1], 'array_walk', 2);
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
        // Objects are accepted, but Zend TypeError text for invalid subjects is "array" (#19836).
        $badSubject = self::jitKnownBadArraySubjectLabel($args[0]);
        if (null !== $badSubject) {
            throw new \TypeError(
                'array_walk(): Argument #1 ($array) must be of type array, '.$badSubject.' given'
            );
        }
        // Runtime-null / boxed non-array under thin AOT (#27632 / peer #26969).
        if (JITVariable::TYPE_VALUE === $args[0]->type || \PHPCompiler\JIT\JitValueBox::isValueOperand($args[0])) {
            JitArrayElem::requireArrayParam($context, $args[0], 'array_walk', 1, 'array');
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            throw new \TypeError(
                'array_walk(): Argument #2 ($callback) must be a valid callback, no array or string given'
            );
        }
        if (!ArrayMapCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        $userdata = 3 === $argc ? $args[2] : null;
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($args[1])) {
            return ArrayWalkRuntime::walkInPlaceWithClosure($context, $args[0], $args[1], $userdata);
        }
        if (null !== $userdata) {
            throw new \LogicException(
                'array_walk() userdata is not supported for JIT/AOT string callbacks in this compiler build (#3627)'
            );
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_walk() callback');
        }

        return ArrayWalkRuntime::walkInPlaceWithStringBuiltin($context, $args[0], $args[1]);
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
        if (VmArrayWalk::isGeneralVmCallable($callback)) {
            return VmArrayWalk::walkArrayFlatVmCallable($frame, $src, $callback, $userdata, 'array_walk');
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        [$internal, $userFn] = VmArrayWalkCallback::resolveString($frame, $callback->toString());
        if (null !== $userFn) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_walk() requires VM context in this compiler build');
            }
            foreach ($src->iterateKeyed(false) as [$key, $value]) {
                $keyCopy = new Variable();
                $keyCopy->copyFrom($key);
                $result = VmArrayWalkCallback::invokeWalkCallback(
                    $frame,
                    null,
                    $userFn,
                    $value,
                    $keyCopy,
                    $userdata
                );
                if (VmArrayWalkCallback::callbackFailed($result)) {
                    return false;
                }
            }

            return true;
        }
        $fn = $internal;
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmInternalCall::invoke($fn, $value, $keyCopy, $userdataCopy);
            } else {
                $result = VmInternalCall::invoke($fn, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
            // Internal callbacks mutate only by-ref slots; ignore return value (php-src array.c, #14830).
            array_map::appendKeyedCopy($out, $key, $value);
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
        if (VmArrayWalk::isGeneralVmCallable($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('array_walk() requires VM context in this compiler build');
            }
            VmArrayWalk::requireVmCallable($frame, $callback, 'array_walk');
            $vm = $frame->vmContext->runtime->vm();
            $iterator = new \PHPCompiler\VM\ObjectPropertyIterator(
                $object,
                $vm,
                $frame,
                \PHPCompiler\VM\ObjectPropertyIterator::PURPOSE_ARRAY_WALK
            );
            $iterator->reset();
            while ($iterator->valid()) {
                $value = $iterator->currentValue(true);
                $keyCopy = $iterator->currentKey();
                if (null !== $userdata) {
                    $userdataCopy = new Variable();
                    $userdataCopy->copyFrom($userdata);
                    $result = VmCallable::invokeAsWithScope(
                        'array_walk',
                        $frame->vmContext,
                        $frame,
                        $callback,
                        $value,
                        $keyCopy,
                        $userdataCopy
                    );
                } else {
                    $result = VmCallable::invokeAsWithScope(
                        'array_walk',
                        $frame->vmContext,
                        $frame,
                        $callback,
                        $value,
                        $keyCopy
                    );
                }
                if (VmArrayWalkCallback::callbackFailed($result)) {
                    return false;
                }
            }

            return true;
        }
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        [$internal, $userFn] = VmArrayWalkCallback::resolveString($frame, $callback->toString());
        $vm = $frame->vmContext->runtime->vm();
        $iterator = new \PHPCompiler\VM\ObjectPropertyIterator(
            $object,
            $vm,
            $frame,
            \PHPCompiler\VM\ObjectPropertyIterator::PURPOSE_ARRAY_WALK
        );
        $iterator->reset();
        $valueByRef = null !== $userFn && isset($userFn->block->paramByRef[0]);
        while ($iterator->valid()) {
            $value = $iterator->currentValue($valueByRef);
            $keyCopy = $iterator->currentKey();
            if (null !== $userFn) {
                $result = VmArrayWalkCallback::invokeWalkCallback(
                    $frame,
                    null,
                    $userFn,
                    $value,
                    $keyCopy,
                    $userdata
                );
            } else {
                if (null !== $userdata) {
                    $userdataCopy = new Variable();
                    $userdataCopy->copyFrom($userdata);
                    $result = VmInternalCall::invoke($internal, $value, $keyCopy, $userdataCopy);
                } else {
                    $result = VmInternalCall::invoke($internal, $value, $keyCopy);
                }
            }
            if (VmArrayWalkCallback::callbackFailed($result)) {
                return false;
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

    /**
     * Compile-time-known invalid array_walk subjects (null/scalars).
     * Hashtable / object / boxed value remain for runtime/object paths.
     */
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
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            default:
                return null;
        }
    }
}
