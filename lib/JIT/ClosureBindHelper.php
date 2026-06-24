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

    public static function registerJitMethods(Context $context): void
    {
        $context->functionProxies['closure::bindto'] = new Call\ClosureBindTo();
        $context->functionProxies['closure::bind'] = new Call\ClosureBind();
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

        self::assertNullableObject($context, $newThis, ClosureBindJitHelper::thisArgLabel($errorContext));
        if (null !== $newScope) {
            self::assertNullableObjectOrString($context, $newScope, ClosureBindJitHelper::scopeArgLabel($errorContext));
        }

        $inner = self::resolveInnerCall($context, $closure);
        if (self::emitStaticClosureInstanceBindNoOp($context, $closure, $inner, $newThis)) {
            return $closure;
        }
        if (null === $inner) {
            return self::nullResult($context);
        }

        $boundThis = self::materializeBoundThis($context, $newThis);
        $boundScope = self::materializeBoundScope($context, $newThis, $newScope);
        $boundObj = self::cloneClosureObject($context, $closure, $boundThis, $boundScope);
        $result = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $boundObj);
        $result->closureCall = new ClosureWithBinding(
            $inner,
            $boundThis,
            $boundScope
        );
        $result->closureIsStatic = $closure->closureIsStatic;

        return $result;
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
        Variable $boundScope
    ): Value {
        $classId = $context->type->object->lookup('Closure');
        $srcObj = self::loadClosureObject($context, $source);
        $dest = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($dest);

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
        $staticFlag = $context->type->object->propertyFetch(
            $srcObj,
            'Closure',
            self::IS_STATIC_PROPERTY
        );
        $context->type->object->storeInstanceProperty(
            $dest,
            'Closure',
            self::IS_STATIC_PROPERTY,
            $staticFlag
        );

        return $dest;
    }

    public static function storeStaticClosureFlag(Context $context, Value $closureObj): void
    {
        self::ensureClosureBindingProperties($context);
        $i1 = $context->getTypeFromString('int1');
        $trueLit = $context->builder->load($i1->constInt(1, false));
        $trueVar = new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $trueLit);
        $trueVar->addref();
        $context->type->object->storeInstanceProperty(
            $closureObj,
            'Closure',
            self::IS_STATIC_PROPERTY,
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

    private static function materializeBoundScope(
        Context $context,
        Variable $newThis,
        ?Variable $newScope
    ): Variable {
        if (null === $newScope) {
            return self::defaultScopeString($context, $newThis);
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
                return self::defaultScopeString($context, $newThis);
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

    private static function defaultScopeString(Context $context, Variable $newThis): Variable
    {
        if (Variable::TYPE_NULL === $newThis->type || ($newThis->isNullConstant ?? false)) {
            return self::emptyScopeString($context);
        }
        if (Variable::TYPE_OBJECT === $newThis->type) {
            return self::classNameStringFromObject($context, $newThis);
        }
        if (Variable::TYPE_VALUE === $newThis->type) {
            return self::classNameStringFromValueBox($context, $newThis);
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
     * Zend zend_closure_bind(): binding an instance to a static closure warns and is a no-op.
     *
     * @return bool true when bind() should return $closure unchanged
     */
    private static function emitStaticClosureInstanceBindNoOp(
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
        self::emitStaticClosureInstanceBindWarning($context);

        return true;
    }

    private static function emitStaticClosureInstanceBindWarning(Context $context): void
    {
        $message = ClosureBindJitHelper::STATIC_BIND_WARNING;
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
