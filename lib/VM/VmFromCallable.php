<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\VM\VmBoundMethodCallable;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithBinding;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\MethodVisibility;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * SSOT for TYPE_FROM_CALLABLE JIT lowering (#4810, #10272).
 *
 * php-src: Zend/zend_closures.c — Closure::fromCallable(), first-class callable
 * php-src: Zend/zend_compile.c — ZEND_AST_CALLABLE_CONVERT
 *
 * VM runtime: {@see ClosureSupport::fromCallable()}
 */
final class VmFromCallable
{
    public static function createClosureVariable(Context $context, Block $block, OpCode $op): JitVariable
    {
        $callableSlot = (int) $op->arg2;
        $fromCallableApi = $op->fromCallableApi;
        $const = self::resolveConstantCallableValue($block, $callableSlot);
        if (null !== $const) {
            if (VmVariable::TYPE_STRING === $const->type) {
                return self::fromStringCallable(
                    $context,
                    $block,
                    $const->toString(),
                    $fromCallableApi
                );
            }
            // Valid-looking array constants may still resolve via bound-method INIT_ARRAY below.
            if (!(VmVariable::TYPE_ARRAY === $const->type && self::arrayConstantLooksCallable($const))) {
                self::throwNonCallableConstant($const, $fromCallableApi);
            }
        }

        // `$c = []; $c(...)` — INIT_ARRAY temp is not a block constant (#28937).
        $arrayArity = self::resolveInitArrayElementCount($block, $callableSlot);
        if (null !== $arrayArity && $arrayArity < 2) {
            if ($fromCallableApi) {
                throw new \TypeError(
                    'Failed to create closure from callable: array callback must have exactly two members'
                );
            }
            throw new \Error(CallableCheck::arrayCallbackTwoElementsMessage());
        }

        $methodLc = VmBoundMethodCallable::resolveMethodLcFromCalleeSlot($block, $callableSlot);
        if (null !== $methodLc) {
            $receiverOp = VmBoundMethodCallable::resolveBoundMethodReceiverOperand($block, $callableSlot);
            if (null !== $receiverOp) {
                $classHint = VmBoundMethodCallable::resolveBoundMethodReceiverClassName($block, $callableSlot);
                $scope = $op->fromCallableScope;
                if ('parent' === $scope) {
                    $classHint = self::resolveParentScopeClassName($context, $block);
                } elseif ('self' === $scope) {
                    $classHint = self::resolveSelfScopeClassName($context, $block);
                }

                return self::fromBoundMethodCallable(
                    $context,
                    $block,
                    $receiverOp,
                    $methodLc,
                    $classHint,
                    null !== $scope
                );
            }
        }

        $receiverOp = VmBoundMethodCallable::resolveInvokableObjectReceiverOperand($block, $callableSlot);
        if (null !== $receiverOp) {
            $classHint = VmBoundMethodCallable::resolveInvokableObjectClassName($block, $callableSlot);

            return self::fromBoundMethodCallable($context, $block, $receiverOp, '__invoke', $classHint);
        }

        throw new \LogicException('TYPE_FROM_CALLABLE: unsupported callable form in JIT');
    }

    /**
     * Walk ASSIGN / CONST_FETCH chains to a known callable value (e.g. `$c = null; $c(...)`) (#28937).
     */
    private static function resolveConstantCallableValue(Block $block, int $slot, array &$visited = []): ?VmVariable
    {
        $visitKey = spl_object_id($block).':'.$slot;
        if (isset($visited[$visitKey])) {
            return null;
        }
        $visited[$visitKey] = true;
        if (isset($block->constants[$slot])) {
            return $block->constants[$slot];
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && $op->arg1 === $slot) {
                $resolved = self::constFetchToVariable($block, (int) $op->arg2);
                if (null !== $resolved) {
                    return $resolved;
                }
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }
            $resolved = self::resolveConstantCallableValue($block, (int) $op->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::resolveConstantCallableValue($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /** Map CONST_FETCH name slot to null/true/false Variable (#28937). */
    private static function constFetchToVariable(Block $block, int $nameSlot): ?VmVariable
    {
        if (!isset($block->constants[$nameSlot])) {
            return null;
        }
        $nameVar = $block->constants[$nameSlot];
        if (VmVariable::TYPE_STRING !== $nameVar->type) {
            return null;
        }
        switch (strtolower($nameVar->toString())) {
            case 'null':
                $v = new VmVariable(VmVariable::TYPE_NULL);
                $v->null();

                return $v;
            case 'true':
                $v = new VmVariable(VmVariable::TYPE_BOOLEAN);
                $v->bool(true);

                return $v;
            case 'false':
                $v = new VmVariable(VmVariable::TYPE_BOOLEAN);
                $v->bool(false);

                return $v;
            default:
                return null;
        }
    }

    /** @throws \TypeError|\Error */
    private static function throwNonCallableConstant(VmVariable $const, bool $fromCallableApi): void
    {
        if ($fromCallableApi) {
            if (VmVariable::TYPE_ARRAY === $const->type) {
                $table = $const->toArray();
                if (2 !== $table->getNumElements()) {
                    throw new \TypeError(
                        'Failed to create closure from callable: array callback must have exactly two members'
                    );
                }
            }
            throw new \TypeError(
                'Failed to create closure from callable: no array or string given'
            );
        }
        if (VmVariable::TYPE_ARRAY === $const->type) {
            $table = $const->toArray();
            $idx0 = new VmVariable(VmVariable::TYPE_INTEGER);
            $idx0->int(0);
            $idx1 = new VmVariable(VmVariable::TYPE_INTEGER);
            $idx1->int(1);
            if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
                throw new \Error(CallableCheck::arrayCallbackTwoElementsMessage());
            }
            $receiver = $table->findVariable($idx0, false)->resolveIndirect();
            $receiverOk = VmVariable::TYPE_OBJECT === $receiver->type
                || VmVariable::TYPE_ENUM_CASE === $receiver->type
                || VmVariable::TYPE_STRING === $receiver->type;
            if (!$receiverOk) {
                throw new \Error(CallableCheck::firstArrayMemberInvalidMessage());
            }
            $methodVar = $table->findVariable($idx1, false)->resolveIndirect();
            if (VmVariable::TYPE_STRING !== $methodVar->type) {
                throw new \Error(CallableCheck::secondArrayMemberInvalidMessage());
            }
        }
        throw new \Error(CallableCheck::scalarNotCallableMessage($const));
    }

    private static function arrayConstantLooksCallable(VmVariable $const): bool
    {
        if (VmVariable::TYPE_ARRAY !== $const->type) {
            return false;
        }
        $table = $const->toArray();
        $idx0 = new VmVariable(VmVariable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new VmVariable(VmVariable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return false;
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodVar = $table->findVariable($idx1, false)->resolveIndirect();
        $receiverOk = VmVariable::TYPE_OBJECT === $receiver->type
            || VmVariable::TYPE_ENUM_CASE === $receiver->type
            || VmVariable::TYPE_STRING === $receiver->type;

        return $receiverOk && VmVariable::TYPE_STRING === $methodVar->type;
    }

    /**
     * Count INIT_ARRAY + ADD_ARRAY_ELEMENT for `$c = […]` FCC operands (#28937).
     *
     * @return null|int null = not an INIT_ARRAY root; else element count
     */
    private static function resolveInitArrayElementCount(Block $block, int $slot, array &$visited = []): ?int
    {
        $visitKey = spl_object_id($block).':'.$slot;
        if (isset($visited[$visitKey])) {
            return null;
        }
        $visited[$visitKey] = true;
        $arraySlot = VmBoundMethodCallable::resolveBoundMethodArrayRootSlot($block, $slot);
        if (null === $arraySlot) {
            // ASSIGN to a plain INIT_ARRAY (empty / one-element) still has a root.
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN === $op->type
                    && ($op->arg2 === $slot || $op->arg1 === $slot)) {
                    $nested = self::resolveInitArrayElementCount($block, (int) $op->arg3, $visited);
                    if (null !== $nested) {
                        return $nested;
                    }
                }
            }
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    continue;
                }
                $nested = self::resolveInitArrayElementCount($parent, $slot, $visited);
                if (null !== $nested) {
                    return $nested;
                }
            }

            return null;
        }
        $count = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && $op->arg1 === $arraySlot) {
                if (null !== $op->arg2) {
                    ++$count;
                }
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $op->type && $op->arg1 === $arraySlot) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Closure::fromCallable('strlen') / 'Class::method' — shared with FCC string form (#26788).
     */
    public static function fromCallableString(Context $context, string $name, ?Block $block = null): JitVariable
    {
        return self::fromStringCallable($context, $block ?? new Block(), $name, true);
    }

    private static function fromStringCallable(
        Context $context,
        Block $block,
        string $name,
        bool $fromCallableApi = false
    ): JitVariable {
        // php-src compile-fatal for `new Class(...)` FCC on every version (#10130, #26188).
        if (str_starts_with($name, 'new ')) {
            throw new \LogicException('Cannot create Closure for new expression');
        }

        if (str_contains($name, '::')) {
            [$className, $methodName] = explode('::', $name, 2);
            $declaringClassLc = strtolower($className);
            $methodLc = strtolower($methodName);
            // Closure::fromCallable([Class, instanceMethod]) binds caller $this (#27138 / #23771).
            if ($fromCallableApi && self::isKnownNonStaticMethod($context, $declaringClassLc, $methodLc)) {
                if (self::blockIsStaticMethodContext($block)) {
                    throw new \TypeError(
                        'Failed to create closure from callable: non-static method '
                        .$className.'::'.$methodName.'() cannot be called statically'
                    );
                }
                $thisOp = new \PHPCfg\Operand\Variable(new \PHPCfg\Operand\Literal('this'));

                return self::fromBoundMethodCallable(
                    $context,
                    $block,
                    $thisOp,
                    $methodLc,
                    $className,
                    false
                );
            }
            self::assertStaticMethodFcc(
                $context,
                $declaringClassLc,
                $methodLc,
                $className,
                $methodName,
                $fromCallableApi
            );
            $proxyName = self::resolveStaticProxyName($context, $block, $declaringClassLc, $methodLc, $className, $methodName);
            $proxy = $context->resolveFunctionProxy($proxyName);

            return self::wrapCallableProxy($context, $proxy, $proxyName);
        }

        $lc = strtolower($name);
        // exit/die remain registered for 8.4 paren-call lowering but are not FCC-visible on 8.2 (#22796).
        if (!$context->functionIsRegistered($lc)
            || !\PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists($lc)) {
            // Zend preserves source spelling in FCC Error messages (#26690, zend_execute_API.c).
            throw new \Error("Call to undefined function {$name}()");
        }

        return self::wrapCallableProxy($context, $context->resolveFunctionProxy($lc), $lc);
    }

    /** FCC closures invoke via {@see JitVariable::$closureCall}; also register __closure_target for AOT (#24166). */
    private static function wrapCallableProxy(Context $context, Call $proxy, string $targetLc): JitVariable
    {
        $targetLc = strtolower($targetLc);
        $context->fccCallableProxies[$targetLc] = $proxy;
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        VmClosure::storeInvokeTarget($context, $obj, $targetLc);
        $var = new JitVariable($context, JitVariable::TYPE_OBJECT, JitVariable::KIND_VALUE, $obj);
        $var->closureCall = $proxy;

        return $var;
    }

    private static function fromBoundMethodCallable(
        Context $context,
        Block $block,
        \PHPCfg\Operand $receiverOp,
        string $methodLc,
        ?string $classHint = null,
        bool $parentScope = false
    ): JitVariable {
        // Enum case FCC receivers often have userType '' while classHint is the enum FQCN (#6845, #9250).
        $className = self::nonEmptyString($receiverOp->type?->userType)
            ?? self::nonEmptyString($classHint)
            ?? ($context->scope->className !== '' ? $context->scope->className : 'object');
        if ($parentScope) {
            $callerLc = self::resolveCallerScopeClassLc($context, $block);
            if (null !== $callerLc && '' !== $callerLc) {
                $parentName = $context->type->object->parentClassDisplayName($callerLc);
                if (null !== $parentName && '' !== $parentName) {
                    $className = $parentName;
                }
            }
        }
        $declaringClassLc = strtolower(ltrim((string) $className, '\\'));
        $proxyName = self::resolveInstanceProxyName($context, $declaringClassLc, $methodLc, $className);
        $inner = $context->resolveFunctionProxy($proxyName);
        $receiverVar = $context->getVariableFromOp($receiverOp);
        $scopeName = self::nonEmptyString($receiverOp->type?->userType)
            ?? self::nonEmptyString($classHint)
            ?? $className;
        if ($parentScope) {
            $scopeName = $className;
        }
        // Snapshot receiver into a value-box so AOT RuntimeIndirect can reload
        // __closure_bound_this (peer #28612 method-closure bind; #28613).
        $boundThis = \PHPCompiler\JIT\ClosureHelper::snapshotCapture($context, $receiverVar);
        $boundScope = new JitVariable(
            $context,
            JitVariable::TYPE_STRING,
            JitVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString((string) $scopeName))
        );
        $boundScope->compileTimeString = (string) $scopeName;
        $closureCall = new ClosureWithBinding($inner, $boundThis, $boundScope);
        $closureVar = self::wrapCallableProxy($context, $closureCall, $proxyName);
        $closureVar->closureIsMethodFake = true;
        $closureObj = $context->helper->loadValue($closureVar);
        ClosureBindHelper::storeFccBoundThisAndScope($context, $closureObj, $boundThis, $boundScope);
        ClosureBindHelper::storeMethodFakeClosureFlag($context, $closureObj);

        return $closureVar;
    }

    private static function resolveParentScopeClassName(Context $context, Block $block): ?string
    {
        $callerLc = self::resolveCallerScopeClassLc($context, $block);
        if (null === $callerLc || '' === $callerLc) {
            return null;
        }

        return $context->type->object->parentClassDisplayName($callerLc);
    }

    private static function resolveSelfScopeClassName(Context $context, Block $block): ?string
    {
        $callerLc = self::resolveCallerScopeClassLc($context, $block);
        if (null === $callerLc || '' === $callerLc) {
            return null;
        }

        return $context->type->object->classNameForId(
            $context->type->object->lookup($callerLc)
        );
    }

    private static function resolveParentScopeClassLc(Context $context, Block $block): ?string
    {
        $callerLc = self::resolveCallerScopeClassLc($context, $block);
        if (null === $callerLc || '' === $callerLc) {
            return null;
        }

        return $context->type->object->parentClassLc($callerLc);
    }

    private static function resolveCallerScopeClassLc(Context $context, Block $block): ?string
    {
        if (null !== $block->func && null !== $block->func->class) {
            return strtolower($block->func->class->value);
        }
        if ($context->scope->className !== '') {
            return $context->scope->className;
        }

        return null;
    }

    /** Zend zend_compile_first_class_callable: reject instance methods on Class::m(...) (#7465). */
    private static function assertStaticMethodFcc(
        Context $context,
        string $calledClassLc,
        string $methodLc,
        string $calledClassName,
        string $methodDisplay,
        bool $fromCallableApi = false
    ): void {
        if ($context->type->object->isEnumClassLc(strtolower(ltrim($calledClassLc, '\\')))
            && 'cases' === $methodLc) {
            return;
        }
        $visited = [];
        $current = strtolower(ltrim($calledClassLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if ($context->type->object->hasDeclaredClass($current)) {
                $classId = $context->type->object->lookup($current);
                if ($context->type->object->hasMethod($classId, $methodLc)) {
                    $vis = $context->type->object->methodVisibility($classId, $methodLc);
                    if (0 === ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                        $declaringName = $context->type->object->classNameForId($classId);
                        $detail = 'non-static method '.$declaringName.'::'.$methodDisplay
                            .'() cannot be called statically';
                        if ($fromCallableApi) {
                            throw new \TypeError('Failed to create closure from callable: '.$detail);
                        }
                        throw new \Error(
                            'Non-static method '.$declaringName.'::'.$methodDisplay.'() cannot be called statically'
                        );
                    }

                    return;
                }
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }
    }

    private static function isKnownNonStaticMethod(
        Context $context,
        string $calledClassLc,
        string $methodLc
    ): bool {
        $visited = [];
        $current = strtolower(ltrim($calledClassLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if ($context->type->object->hasDeclaredClass($current)) {
                $classId = $context->type->object->lookup($current);
                if ($context->type->object->hasMethod($classId, $methodLc)) {
                    $vis = $context->type->object->methodVisibility($classId, $methodLc);

                    return 0 === ($vis & \PHPCfg\Func::FLAG_STATIC);
                }
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return false;
    }

    private static function blockIsStaticMethodContext(Block $block): bool
    {
        if (null === $block->func) {
            return true;
        }

        return (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0
            || null === $block->func->class;
    }

    private static function resolveStaticProxyName(
        Context $context,
        Block $block,
        string $declaringClassLc,
        string $methodLc,
        string $className,
        string $methodName
    ): string {
        $declaringClassId = $context->type->object->lookup($className);
        $visFlags = $context->type->object->methodVisibility($declaringClassId, $methodLc);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($context->scope->className !== '') {
            $callerClassLc = $context->scope->className;
        }
        try {
            MethodVisibility::assertCallable(
                $visFlags,
                $callerClassLc,
                $declaringClassLc,
                $className,
                $methodName,
                false
            );
        } catch (\LogicException $e) {
            // FCC: same Error wording as a direct call (#25689, zend_object_handlers.c).
            throw new \Error($e->getMessage());
        }
        $proxyName = strtolower($className.'::'.$methodName);
        if (!$context->functionIsRegistered($proxyName)) {
            // Zend zend_execute_API.c — catchable Error, same wording as a direct miss (#27921, #28003).
            throw new \Error("Call to undefined method {$className}::{$methodName}()");
        }

        return $proxyName;
    }

    private static function resolveInstanceProxyName(
        Context $context,
        string $declaringClassLc,
        string $methodLc,
        string $className
    ): string {
        if ('object' === $declaringClassLc) {
            if ('getname' === $methodLc && $context->functionIsRegistered('reflectionattribute::getname')) {
                $declaringClassLc = 'reflectionattribute';
                $className = 'ReflectionAttribute';
            } elseif ('getattributes' === $methodLc && $context->functionIsRegistered('reflectionmethod::getattributes')) {
                $declaringClassLc = 'reflectionmethod';
                $className = 'ReflectionMethod';
            }
        }
        $proxyName = strtolower($className.'::'.$methodLc);
        if ($context->functionIsRegistered($proxyName)) {
            return $proxyName;
        }
        // Inherited instance methods: receiver class may be a subclass (#27143 AOT FCC/fromCallable).
        $current = $declaringClassLc;
        $visited = [];
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $parentLc = $context->type->object->parentClassLc($current);
            if (null === $parentLc || '' === $parentLc) {
                break;
            }
            $parentDisplay = $context->type->object->parentClassDisplayName($current) ?? $parentLc;
            $proxyName = strtolower($parentDisplay.'::'.$methodLc);
            if ($context->functionIsRegistered($proxyName)) {
                return $proxyName;
            }
            $current = $parentLc;
        }

        // Catchable Error for missing instance-method FCC (#28003, zend_execute_API.c).
        throw new \Error("Call to undefined method {$className}::{$methodLc}()");
    }

    private static function nonEmptyString(?string $value): ?string
    {
        return null !== $value && '' !== $value ? $value : null;
    }
}
