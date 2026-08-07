<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ClosureBindRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithBinding;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\VM\ClosureBindJitHelper;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Closure::bind / bindTo JIT lowering (#4192, Zend zend_closures.c).
 */
final class ClosureBindHelper
{
    public const BOUND_THIS_PROPERTY = '__closure_bound_this';

    public const BOUND_SCOPE_PROPERTY = '__closure_bound_scope';

    public const IS_STATIC_PROPERTY = '__closure_is_static';

    /** Marks fromCallable/FCC method wrappers (zend ZEND_ACC_FAKE_CLOSURE, #23421). */
    public const IS_METHOD_PROPERTY = '__closure_is_method';

    public static function registerJitMethods(Context $context): void
    {
        $context->functionProxies['closure::bindto'] = new Call\ClosureBindTo();
        $context->functionProxies['closure::bind'] = new Call\ClosureBind();
        // Avoid ExternalMethod null stub on user-script AOT (#26788).
        $context->functionProxies['closure::fromcallable'] = new Call\ClosureFromCallable();
        // Avoid ExternalMethod silent-null on user-script AOT (#26872).
        $context->functionProxies['closure::call'] = new Call\ClosureCall();
    }

    /**
     * Closure::call() — invoke once with a temporary $this (Zend/zend_closures.c, #26872).
     */
    public static function invokeCall(
        Context $context,
        Variable $closure,
        Variable $newThis,
        Variable ...$invokeArgs
    ): Value {
        self::ensureClosureBindingProperties($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        self::assertObject($context, $newThis, ClosureBindJitHelper::thisArgLabel('Closure::call()'));

        $resolved = ClosureHelper::resolveCall($context, $closure);
        $innerForGuards = null === $resolved ? null : self::unwrapInnerCall($resolved);
        if (self::emitStaticClosureInstanceBindFailure($context, $closure, $innerForGuards, $newThis)) {
            return self::boxReturn($context, self::nullResult($context));
        }

        $boundThis = self::materializeBoundThis($context, $newThis);
        // ZEND_METHOD(Closure, call): scope becomes the class of $newThis.
        $boundScope = self::scopeForCallNewThis($context, $newThis);

        if (
            null !== $resolved
            && !($resolved instanceof Call\RuntimeIndirectClosureCall)
            && !(self::unwrapInnerCall($resolved) instanceof Call\RuntimeIndirectClosureCall)
        ) {
            $inner = self::unwrapInnerCall($resolved);

            return (new ClosureWithBinding($inner, $boundThis, $boundScope))->call(
                $context,
                ...$invokeArgs
            );
        }

        $candidates = ClosureHelper::closureCandidates($context);
        if ([] === $candidates) {
            return self::boxReturn($context, self::nullResult($context));
        }

        // Single-closure scripts: prefer direct invoke (binding props may be absent on older objs).
        if (1 === \count($candidates)) {
            $inner = self::unwrapInnerCall(\reset($candidates));

            return (new ClosureWithBinding($inner, $boundThis, $boundScope))->call(
                $context,
                ...$invokeArgs
            );
        }

        if (null !== $context->lastClosureCallProxy) {
            $inner = self::unwrapInnerCall($context->lastClosureCallProxy);

            return (new ClosureWithBinding($inner, $boundThis, $boundScope))->call(
                $context,
                ...$invokeArgs
            );
        }

        $obj = ClosureHelper::loadObjectFromCallable($context, $closure);
        $context->type->object->storeInstanceProperty(
            $obj,
            'Closure',
            self::BOUND_THIS_PROPERTY,
            $boundThis
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            'Closure',
            self::BOUND_SCOPE_PROPERTY,
            $boundScope
        );
        $classId = $context->type->object->lookup('Closure');
        $indirect = new Call\RuntimeIndirectClosureCall($closure, $candidates, $classId);

        return $indirect->call($context, ...$invokeArgs);
    }

    /**
     * Closure::call scope is get_class($newThis). Prefer compile-time class name from NEW (#26872).
     */
    private static function scopeForCallNewThis(Context $context, Variable $newThis): Variable
    {
        if (Variable::TYPE_OBJECT === $newThis->type && null !== $newThis->compileTimeString
            && '' !== $newThis->compileTimeString
        ) {
            return self::stringVariable($context, $newThis->compileTimeString);
        }

        // Avoid ReflectionSetup class-name path in thin AOT — empty scope still allows
        // runtime property slot dispatch for public props; private needs compile-time name.
        return self::emptyScopeString($context);
    }

    /** Closure::call() requires a non-null object $newThis (zend_closures.stub.php). */
    private static function assertObject(
        Context $context,
        Variable $arg,
        string $label
    ): void {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return;
        }
        if (Variable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            self::raiseTypeError($context, $label, 'object', 'null');

            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::emitValueBoxObjectCheck($context, $arg, $label);

            return;
        }
        if (ClosureBindJitHelper::jitTypeIsInvalidNullableObject($arg->type)) {
            self::raiseTypeError(
                $context,
                $label,
                'object',
                ClosureBindJitHelper::jitScalarTypeLabel($arg->type)
            );
        }
    }

    public static function ensureClosureBindingProperties(Context $context): void
    {
        $classId = $context->type->object->lookup('Closure');
        $objectType = $context->type->object;
        if (!$objectType->hasProperty($classId, self::BOUND_THIS_PROPERTY)) {
            $objectType->defineProperty($classId, self::BOUND_THIS_PROPERTY, Variable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($classId, self::BOUND_SCOPE_PROPERTY)) {
            $objectType->defineProperty($classId, self::BOUND_SCOPE_PROPERTY, Variable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($classId, self::IS_STATIC_PROPERTY)) {
            $objectType->defineProperty($classId, self::IS_STATIC_PROPERTY, Variable::TYPE_NATIVE_BOOL);
        }
        if (!$objectType->hasProperty($classId, self::IS_METHOD_PROPERTY)) {
            $objectType->defineProperty($classId, self::IS_METHOD_PROPERTY, Variable::TYPE_NATIVE_BOOL);
        }
    }

    public static function bind(
        Context $context,
        Variable $closure,
        Variable $newThis,
        ?Variable $newScope,
        string $errorContext
    ): Variable {
        self::ensureClosureBindingProperties($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        // Avoid stamping a prior bind's ClosureWithBinding onto a null/failed result (#27219).
        if ($context->lastClosureCallProxy instanceof ClosureWithBinding) {
            $context->lastClosureCallProxy = null;
        }

        self::assertNullableObject($context, $newThis, ClosureBindJitHelper::thisArgLabel($errorContext));
        if (null !== $newScope) {
            self::assertNullableObjectOrString($context, $newScope, ClosureBindJitHelper::scopeArgLabel($errorContext));
        }

        $inner = self::resolveInnerCall($context, $closure);
        if (self::emitMethodFakeUnbindFailure($context, $closure, $inner, $newThis)) {
            return self::nullResult($context);
        }
        if (self::emitUnbindThisFailure($context, $closure, $inner, $newThis)) {
            return self::nullResult($context);
        }
        if (self::emitStaticClosureInstanceBindFailure($context, $closure, $inner, $newThis)) {
            return self::nullResult($context);
        }
        // Free-closure receivers often lose Variable::closureCall across assigns; mirror
        // Closure::call single-candidate / lastClosureCallProxy recovery (#26872 / #27219).
        if (null === $inner) {
            $inner = self::resolveInnerCallFallback($context);
        }
        if (null === $inner) {
            return self::nullResult($context);
        }

        $boundThis = self::materializeBoundThis($context, $newThis);
        $boundScope = self::materializeBoundScope($context, $closure, $newScope);
        $boundObj = self::cloneClosureObject($context, $closure, $boundThis, $boundScope, $inner);
        $result = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $boundObj);
        $boundCall = new ClosureWithBinding(
            $inner,
            $boundThis,
            $boundScope
        );
        $result->closureCall = $boundCall;
        $result->closureIsStatic = $closure->closureIsStatic;
        // boxReturn() returns only a Value; stash ClosureWithBinding for FUNCCALL_EXEC_RETURN (#27219).
        $context->lastClosureCallProxy = $boundCall;

        return $result;
    }

    /**
     * When bind/bindTo receiver metadata was dropped, recover the only {closure}_*
     * candidate (or last allocated proxy) — same strategy as {@see invokeCall} (#27219).
     */
    private static function resolveInnerCallFallback(Context $context): ?Call
    {
        $candidates = ClosureHelper::closureCandidates($context);
        if (1 === \count($candidates)) {
            return self::unwrapInnerCall(\reset($candidates));
        }
        if (null !== $context->lastClosureCallProxy) {
            $proxy = $context->lastClosureCallProxy;
            if (!($proxy instanceof ClosureWithBinding)) {
                return self::unwrapInnerCall($proxy);
            }
        }

        return null;
    }

    public static function wrapCallWithBindingFromObject(
        Context $context,
        Value $closureObj,
        Call $inner,
        Variable ...$args
    ): Value {
        self::ensureClosureBindingProperties($context);
        $boundThis = $context->type->object->propertyFetch(
            $closureObj,
            'Closure',
            self::BOUND_THIS_PROPERTY
        );
        if ($boundThis->isNullConstant ?? false) {
            return $inner->call($context, ...$args);
        }
        $boundScope = $context->type->object->propertyFetch(
            $closureObj,
            'Closure',
            self::BOUND_SCOPE_PROPERTY
        );

        return (new ClosureWithBinding($inner, $boundThis, $boundScope))->call($context, ...$args);
    }

    public static function unwrapInnerCall(Call $call): Call
    {
        while ($call instanceof ClosureWithBinding) {
            $call = $call->inner();
        }
        if ($call instanceof ClosureWithCaptures) {
            return $call->innerNative();
        }

        return $call;
    }

    /**
     * @param list<Variable> $args
     *
     * @return list<Variable>
     */
    public static function prependBoundThisForInvoke(
        Context $context,
        Call $inner,
        Variable $boundThis,
        array $args
    ): array {
        if (!self::closureInnerUsesThis($context, $inner)) {
            return $args;
        }
        if ($boundThis->isNullConstant ?? false) {
            return $args;
        }
        $thisArg = self::boundThisAsObject($context, $boundThis);

        return array_merge([$thisArg], $args);
    }

    public static function compileTimeScopeName(Variable $boundScope): string
    {
        if (Variable::TYPE_STRING === $boundScope->type && null !== $boundScope->compileTimeString) {
            return $boundScope->compileTimeString;
        }

        return '';
    }

    public static function boxReturn(Context $context, Variable $result): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (Variable::TYPE_OBJECT === $result->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($result)
            );
        } else {
            JitValueBox::copyFromPointer($context, $slot, JitValueBox::valuePtrFromVariable($context, $result));
        }

        return $ptr;
    }

    private static function closureInnerUsesThis(Context $context, Call $inner): bool
    {
        if (!$inner instanceof Native) {
            return false;
        }
        if (0 === $inner->function->countParams()) {
            return false;
        }
        $first = $inner->function->getParam(0);

        return '__object__*' === $context->getStringFromType($first->typeOf());
    }

    private static function resolveInnerCall(Context $context, Variable $closure): ?Call
    {
        if (null !== $closure->closureCall) {
            return self::unwrapInnerCall($closure->closureCall);
        }
        if (Variable::TYPE_OBJECT !== $closure->type && Variable::TYPE_VALUE !== $closure->type) {
            return null;
        }
        $obj = Variable::TYPE_OBJECT === $closure->type
            ? $context->helper->loadValue($closure)
            : $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $closure)
            );
        $targetVar = $context->type->object->propertyFetch(
            $obj,
            'Closure',
            ClosureHelper::TARGET_PROPERTY
        );
        if (Variable::TYPE_STRING !== $targetVar->type || null === $targetVar->compileTimeString) {
            return null;
        }
        $proxy = $context->functionProxies[strtolower($targetVar->compileTimeString)] ?? null;

        return $proxy instanceof Call ? $proxy : null;
    }

    private static function cloneClosureObject(
        Context $context,
        Variable $source,
        Variable $boundThis,
        Variable $boundScope,
        ?Call $inner = null
    ): Value {
        $classId = $context->type->object->lookup('Closure');
        $dest = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($dest);

        // Prefer the resolved invoke name: fetch→store of __closure_target can leave TARGET
        // empty so RuntimeIndirect aborts on mismatch (#27219).
        $targetName = self::nativeInvokeTargetName($inner);
        if (null !== $targetName) {
            ClosureHelper::storeInvokeTarget($context, $dest, $targetName);
        } else {
            $srcObj = self::loadClosureObject($context, $source);
            $targetVar = $context->type->object->propertyFetch(
                $srcObj,
                'Closure',
                ClosureHelper::TARGET_PROPERTY
            );
            $context->type->object->storeInstanceProperty(
                $dest,
                'Closure',
                ClosureHelper::TARGET_PROPERTY,
                $targetVar
            );
        }
        $context->type->object->storeInstanceProperty(
            $dest,
            'Closure',
            self::BOUND_THIS_PROPERTY,
            $boundThis
        );
        $context->type->object->storeInstanceProperty(
            $dest,
            'Closure',
            self::BOUND_SCOPE_PROPERTY,
            $boundScope
        );
        // Do not propertyFetch/copy IS_STATIC / IS_METHOD from the source: free closures
        // leave those slots uninitialized and the copy segfaults under thin AOT (#27219).
        // Set flags from Variable metadata when present (method FCC / static closures).
        if ($source->closureIsStatic) {
            self::storeStaticClosureFlag($context, $dest);
        }
        if ($source->closureIsMethodFake) {
            self::storeMethodFakeClosureFlag($context, $dest);
        }

        return $dest;
    }

    /** Lowercase {closure}_N name for RuntimeIndirect dispatch, if $inner is Native. */
    private static function nativeInvokeTargetName(?Call $inner): ?string
    {
        if (null === $inner) {
            return null;
        }
        $inner = self::unwrapInnerCall($inner);
        if (!$inner instanceof Native) {
            return null;
        }
        $name = strtolower($inner->name);

        return str_starts_with($name, '{closure}_') ? $name : null;
    }

    public static function storeStaticClosureFlag(Context $context, Value $closureObj): void
    {
        self::ensureClosureBindingProperties($context);
        $i1 = $context->getTypeFromString('int1');
        // constInt already yields an i1 value — load() would treat it as a pointer and
        // SIGSEGV inside LLVM (static fn / static function AOT, #24836).
        $trueLit = $i1->constInt(1, false);
        $trueVar = new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $trueLit);
        $trueVar->addref();
        $context->type->object->storeInstanceProperty(
            $closureObj,
            'Closure',
            self::IS_STATIC_PROPERTY,
            $trueVar
        );
    }

    /**
     * Persist FCC / fromCallable bound $this + scope on the Closure object.
     *
     * AOT invoke goes through {@see Call\RuntimeIndirectClosureCall} →
     * {@see wrapCallWithBindingFromObject}; without these slots, instance-method
     * FCC aborts (empty output / SIGABRT) while JIT still works via Variable::closureCall (#28613).
     */
    public static function storeFccBoundThisAndScope(
        Context $context,
        Value $closureObj,
        Variable $boundThis,
        Variable $boundScope
    ): void {
        self::ensureClosureBindingProperties($context);
        $context->type->object->storeInstanceProperty(
            $closureObj,
            'Closure',
            self::BOUND_THIS_PROPERTY,
            $boundThis
        );
        $context->type->object->storeInstanceProperty(
            $closureObj,
            'Closure',
            self::BOUND_SCOPE_PROPERTY,
            $boundScope
        );
    }

    /** Mark FCC / fromCallable method wrappers for unbind diagnostics (#23421). */
    public static function storeMethodFakeClosureFlag(Context $context, Value $closureObj): void
    {
        self::ensureClosureBindingProperties($context);
        $i1 = $context->getTypeFromString('int1');
        $trueLit = $i1->constInt(1, false);
        $trueVar = new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $trueLit);
        $trueVar->addref();
        $context->type->object->storeInstanceProperty(
            $closureObj,
            'Closure',
            self::IS_METHOD_PROPERTY,
            $trueVar
        );
    }

    private static function loadClosureObject(Context $context, Variable $closure): Value
    {
        if (Variable::TYPE_OBJECT === $closure->type) {
            return $context->helper->loadValue($closure);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $closure)
        );
    }

    private static function materializeBoundThis(Context $context, Variable $newThis): Variable
    {
        if (Variable::TYPE_NULL === $newThis->type) {
            return self::nullValueBox($context);
        }
        if (Variable::TYPE_OBJECT === $newThis->type) {
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                JitValueBox::pointer($context, $slot),
                $context->helper->loadValue($newThis)
            );
            $var = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
            $var->addref();

            return $var;
        }

        return ClosureHelper::snapshotCapture($context, $newThis);
    }

    /**
     * Omitted $newScope / "static" keep the prior bound scope (#24244, zend_closures.c).
     * Explicit null unbinds (#10097). Never adopt get_class($newThis) for those cases.
     */
    private static function materializeBoundScope(
        Context $context,
        Variable $closure,
        ?Variable $newScope
    ): Variable {
        if (null === $newScope) {
            return self::priorBoundScopeString($context, $closure);
        }
        if (Variable::TYPE_NULL === $newScope->type) {
            // bindTo($obj, null) — unbound scope; do not inherit $newThis class (#10097).
            return self::emptyScopeString($context);
        }
        if (Variable::TYPE_STRING === $newScope->type) {
            $scope = $newScope->compileTimeString ?? '';
            if ('' === $scope && Variable::KIND_VALUE === $newScope->kind) {
                $scope = self::loadStringFromVariable($context, $newScope);
            }
            if (ClosureBindJitHelper::resolveStaticScopeAlias($scope)) {
                return self::priorBoundScopeString($context, $closure);
            }

            return self::stringVariable($context, $scope);
        }
        if (Variable::TYPE_OBJECT === $newScope->type) {
            return self::classNameStringFromObject($context, $newScope);
        }
        if (Variable::TYPE_VALUE === $newScope->type) {
            return self::classNameStringFromValueBox($context, $newScope);
        }

        throw new \LogicException('Closure bind scope resolution failed in JIT');
    }

    /** Read __closure_bound_scope from the source closure (omitted / "static" scope). */
    private static function priorBoundScopeString(Context $context, Variable $closure): Variable
    {
        if (
            null !== $closure->closureCall
            && $closure->closureCall instanceof ClosureWithBinding
        ) {
            $prior = $closure->closureCall->boundScope();
            if (Variable::TYPE_STRING === $prior->type) {
                return $prior;
            }
        }
        if (Variable::TYPE_OBJECT !== $closure->type && Variable::TYPE_VALUE !== $closure->type) {
            return self::emptyScopeString($context);
        }
        self::ensureClosureBindingProperties($context);
        $srcObj = self::loadClosureObject($context, $closure);
        $prior = $context->type->object->propertyFetch(
            $srcObj,
            'Closure',
            self::BOUND_SCOPE_PROPERTY
        );
        if (Variable::TYPE_STRING === $prior->type) {
            return $prior;
        }

        return self::emptyScopeString($context);
    }

    private static function emptyScopeString(Context $context): Variable
    {
        $lit = $context->builder->load($context->constantStringFromString(''));
        $var = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $lit);
        $var->compileTimeString = '';
        $var->addref();

        return $var;
    }

    private static function nullValueBox(Context $context): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $var = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        $var->isNullConstant = true;
        $var->addref();

        return $var;
    }

    private static function nullResult(Context $context): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function boundThisAsObject(Context $context, Variable $boundThis): Variable
    {
        if (Variable::TYPE_OBJECT === $boundThis->type) {
            return $boundThis;
        }
        $slot = JitValueBox::alloc($context);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $boundThis)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->addref();

        return $var;
    }

    private static function stringVariable(Context $context, string $scope): Variable
    {
        $lit = $context->builder->load($context->constantStringFromString($scope));
        $var = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $lit);
        $var->compileTimeString = $scope;
        $var->addref();

        return $var;
    }

    private static function classNameStringFromObject(Context $context, Variable $object): Variable
    {
        $obj = $context->helper->loadValue($object);

        return self::runtimeClassNameFromObject($context, $obj);
    }

    private static function classNameStringFromValueBox(Context $context, Variable $box): Variable
    {
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $box)
        );
        $tmp = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $tmp->addref();

        return self::classNameStringFromObject($context, $tmp);
    }

    private static function runtimeClassNameFromObject(Context $context, Value $obj): Variable
    {
        $i64 = $context->getTypeFromString('int64');
        [$cstr, $len] = Builtin\ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $len64 = $context->builder->zExt($len, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len64,
            $cstr
        );
        $var = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str);
        $var->addref();

        return $var;
    }

    private static function loadStringFromVariable(Context $context, Variable $str): string
    {
        if (null !== $str->compileTimeString) {
            return $str->compileTimeString;
        }
        if (Variable::KIND_VALUE === $str->kind) {
            return '';
        }

        return '';
    }

    private static function assertNullableObject(
        Context $context,
        Variable $arg,
        string $label
    ): void {
        if (Variable::TYPE_NULL === $arg->type || Variable::TYPE_OBJECT === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::emitValueBoxObjectOrNullCheck($context, $arg, $label);

            return;
        }
        if (ClosureBindJitHelper::jitTypeIsInvalidNullableObject($arg->type)) {
            self::raiseTypeError(
                $context,
                $label,
                '?object',
                ClosureBindJitHelper::jitScalarTypeLabel($arg->type)
            );
        }
    }

    private static function assertNullableObjectOrString(
        Context $context,
        Variable $arg,
        string $label
    ): void {
        if (Variable::TYPE_NULL === $arg->type
            || Variable::TYPE_OBJECT === $arg->type
            || Variable::TYPE_STRING === $arg->type
        ) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::emitValueBoxObjectStringOrNullCheck($context, $arg, $label);

            return;
        }
        if (ClosureBindJitHelper::jitTypeIsInvalidNullableObjectOrString($arg->type)) {
            self::raiseTypeError(
                $context,
                $label,
                'object|string|null',
                ClosureBindJitHelper::jitScalarTypeLabel($arg->type)
            );
        }
    }

    private static function emitValueBoxObjectOrNullCheck(
        Context $context,
        Variable $arg,
        string $label
    ): void {
        $ptr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeByte = self::loadValueTypeByte($context, $ptr);
        ClosureBindRuntime::ensureLinked($context);
        $kind = ClosureBindRuntime::callValueBoxKindForNullableObject($context, $typeByte);
        $i32 = $context->getTypeFromString('int32');
        $invalidBlock = BasicBlockHelper::append($context, 'closure_bind_this_fail');
        $mergeBlock = BasicBlockHelper::append($context, 'closure_bind_this_merge');
        $isInvalid = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i32->constInt(ClosureBindJitHelper::KIND_INVALID, false)
        );
        $context->builder->branchIf($isInvalid, $invalidBlock, $mergeBlock);
        $context->builder->positionAtEnd($invalidBlock);
        self::raiseTypeError($context, $label, '?object', 'int');
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);
    }

    /** Value-box $newThis for Closure::call — object only (null rejected). */
    private static function emitValueBoxObjectCheck(
        Context $context,
        Variable $arg,
        string $label
    ): void {
        $ptr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeByte = self::loadValueTypeByte($context, $ptr);
        ClosureBindRuntime::ensureLinked($context);
        $kind = ClosureBindRuntime::callValueBoxKindForNullableObject($context, $typeByte);
        $i32 = $context->getTypeFromString('int32');
        $invalidBlock = BasicBlockHelper::append($context, 'closure_call_this_fail');
        $mergeBlock = BasicBlockHelper::append($context, 'closure_call_this_merge');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i32->constInt(ClosureBindJitHelper::KIND_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $mergeBlock, $invalidBlock);
        $context->builder->positionAtEnd($invalidBlock);
        self::raiseTypeError($context, $label, 'object', 'null');
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);
    }

    private static function emitValueBoxObjectStringOrNullCheck(
        Context $context,
        Variable $arg,
        string $label
    ): void {
        $ptr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeByte = self::loadValueTypeByte($context, $ptr);
        ClosureBindRuntime::ensureLinked($context);
        $kind = ClosureBindRuntime::callValueBoxKindForNullableObjectOrString($context, $typeByte);
        $i32 = $context->getTypeFromString('int32');
        $invalidBlock = BasicBlockHelper::append($context, 'closure_bind_scope_fail');
        $mergeBlock = BasicBlockHelper::append($context, 'closure_bind_scope_merge');
        $isInvalid = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i32->constInt(ClosureBindJitHelper::KIND_INVALID, false)
        );
        $context->builder->branchIf($isInvalid, $invalidBlock, $mergeBlock);
        $context->builder->positionAtEnd($invalidBlock);
        self::raiseTypeError($context, $label, 'object|string|null', 'int');
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);
    }

    private static function loadValueTypeByte(Context $context, Value $valuePtr): Value
    {
        return $context->builder->load(
            $context->builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
    }

    private static function raiseTypeError(
        Context $context,
        string $label,
        string $expected,
        string $given
    ): void {
        TypeErrorRaise::emitRaise(
            $context,
            "{$label} must be of type {$expected}, {$given} given"
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Zend zend_closure_bind_to(): fake non-static method cannot unbind $this (#23421).
     *
     * @return bool true when bind() should return null
     */
    private static function emitMethodFakeUnbindFailure(
        Context $context,
        Variable $closure,
        ?Call $inner,
        Variable $newThis
    ): bool {
        if (Variable::TYPE_NULL !== $newThis->type && !($newThis->isNullConstant ?? false)) {
            return false;
        }
        if (!self::isMethodFakeClosure($context, $closure, $inner)) {
            return false;
        }
        self::emitBindWarning($context, ClosureBindJitHelper::UNBIND_THIS_OF_METHOD_WARNING);

        return true;
    }

    /**
     * Zend zend_closure_bind_to(): cannot unbind when this_ptr is set AND USES_THIS (#23387).
     *
     * @return bool true when bind() should return null
     */
    private static function emitUnbindThisFailure(
        Context $context,
        Variable $closure,
        ?Call $inner,
        Variable $newThis
    ): bool {
        if (Variable::TYPE_NULL !== $newThis->type && !($newThis->isNullConstant ?? false)) {
            return false;
        }
        if (
            null === $inner
            || !ClosureBindJitHelper::shouldRejectUnbindThis(
                self::closureInnerUsesThis($context, $inner),
                self::closureHasBoundThis($closure)
            )
        ) {
            return false;
        }
        self::emitBindWarning(
            $context,
            ClosureBindJitHelper::unbindThisWarning(
                self::isMethodFakeClosure($context, $closure, $inner)
            )
        );

        return true;
    }

    /** Compile-time / object-flag detection of ZEND_ACC_FAKE_CLOSURE methods (#23421). */
    private static function isMethodFakeClosure(Context $context, Variable $closure, ?Call $inner): bool
    {
        if ($closure->closureIsMethodFake) {
            return true;
        }
        if ($closure->closureIsStatic) {
            return false;
        }
        $call = $closure->closureCall ?? null;
        if ($call instanceof ClosureWithBinding) {
            $unwrapped = self::unwrapInnerCall($call);
            if (self::callLooksLikeClassMethod($unwrapped)) {
                return true;
            }
        }
        if (null !== $inner && self::callLooksLikeClassMethod($inner)) {
            return true;
        }

        return false;
    }

    private static function callLooksLikeClassMethod(Call $call): bool
    {
        if (!$call instanceof Native) {
            return false;
        }
        $name = strtolower($call->name);
        if (!str_contains($name, '::')) {
            return false;
        }

        return !str_contains($name, '{closure}');
    }

    /** Compile-time this_ptr set? Matches ClosureState::hasBoundThis() for known bindings. */
    private static function closureHasBoundThis(Variable $closure): bool
    {
        $call = $closure->closureCall ?? null;
        if (!$call instanceof ClosureWithBinding) {
            return false;
        }
        $boundThis = $call->boundThis();

        return !($boundThis->isNullConstant ?? false)
            && Variable::TYPE_NULL !== $boundThis->type;
    }

    /**
     * Zend zend_closure_bind(): binding an instance to a static closure warns and returns null.
     *
     * @return bool true when bind() should return null
     */
    private static function emitStaticClosureInstanceBindFailure(
        Context $context,
        Variable $closure,
        ?Call $inner,
        Variable $newThis
    ): bool {
        if (Variable::TYPE_NULL === $newThis->type || ($newThis->isNullConstant ?? false)) {
            return false;
        }
        if (Variable::TYPE_OBJECT !== $newThis->type || !$closure->closureIsStatic) {
            return false;
        }
        self::emitBindWarning($context, ClosureBindJitHelper::STATIC_BIND_WARNING);

        return true;
    }

    private static function emitBindWarning(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(max(0, $context->callSiteLine), false)
        );
    }

}
