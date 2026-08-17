<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * Uninitialized typed property read guards for JIT/AOT (#4569, #4614, zend_object_handlers.c).
 */
final class TypedPropertyUninitGuard
{
    public static function emitBeforeRead(Context $context, Variable $var): void
    {
        // M5 argv / gen-0 seed: CFG split + NestedJIT ErrorRaise leaves parentless / mid-BB
        // terminators while host-lowering Runtime::parse (#26756). Typed init checks are not
        // required for never-seen functional smoke.
        $m5 = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        if ('1' === $m5 || 'true' === strtolower((string) $m5)) {
            return;
        }
        if (Variable::TYPE_VALUE !== $var->type) {
            return;
        }
        if (null === $var->objectPropertyClassName || null === $var->objectPropertyName) {
            return;
        }
        $object = $context->type->object;
        assert($object instanceof Object_);
        $resolved = $object->resolvePropertySlot($var->objectPropertyClassName, $var->objectPropertyName);
        if (null === $resolved) {
            return;
        }
        [$classId, $slotIndex] = $resolved;
        $requiresTypedGuard = $object->propertySlotRequiresTypedInitGuard($classId, $slotIndex);
        $valuePtr = self::valuePtrFromVariable($context, $var);
        if (null === $valuePtr) {
            return;
        }
        $declaringClass = $object->instancePropertyDeclaringClassName($classId, (string) $var->objectPropertyName);

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $checkBlock = $fn->appendBasicBlock('typed_prop_uninit_check');
        $okBlock = $fn->appendBasicBlock('typed_prop_uninit_ok');
        $exitBlock = $fn->appendBasicBlock('typed_prop_uninit_exit');
        $raiseBlock = $requiresTypedGuard ? $fn->appendBasicBlock('typed_prop_uninit_raise') : null;
        $undefWarnBlock = $requiresTypedGuard ? null : $fn->appendBasicBlock('untyped_prop_undef_warn');

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($checkBlock);

        $context->builder->positionAtEnd($checkBlock);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        if ($requiresTypedGuard) {
            assert(null !== $raiseBlock);
            $context->builder->branchIf($isUndef, $raiseBlock, $okBlock);

            $context->builder->positionAtEnd($raiseBlock);
            $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                ErrorRaise::ensureStandaloneBodies($context);
            }
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            ErrorRaise::emitRaise(
                $context,
                sprintf(
                    'Typed property %s::$%s must not be accessed before initialization',
                    MethodVisibility::formatAnonymousScopeForMessage((string) $declaringClass),
                    $var->objectPropertyName
                )
            );
            // Thin user-script AOT skips end-of-main abort (#21467); abort here.
            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
            }
            self::emitRaiseAndTerminate($context);
        } else {
            assert(null !== $undefWarnBlock);
            $context->builder->branchIf($isUndef, $undefWarnBlock, $okBlock);

            $context->builder->positionAtEnd($undefWarnBlock);
            $savedWarn = BasicBlockHelper::tryGetInsertBlock($context);
            \PHPCompiler\JIT\Builtin\UndefinedPropertyFetchRuntime::emitWarning(
                $context,
                $declaringClass,
                (string) $var->objectPropertyName
            );
            BasicBlockHelper::restoreInsertBlock($context, $savedWarn);
            // Zend: undefined untyped declared read → NULL (#22021).
            $context->builder->store(
                $i8->constInt(VmVariable::TYPE_NULL, false),
                $context->builder->structGep($valuePtr, $map['type'])
            );
            $context->builder->branch($okBlock);
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    /**
     * Uninitialized typed property by-ref fetch (#31771, zend_object_handlers.c get_property_ptr_ptr).
     *
     * Non-nullable: Error. Nullable: ZVAL_NULL then alias.
     */
    public static function emitBeforeByRef(Context $context, Variable $var): void
    {
        $m5 = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        if ('1' === $m5 || 'true' === strtolower((string) $m5)) {
            return;
        }
        if (Variable::TYPE_VALUE !== $var->type) {
            return;
        }
        if (null === $var->objectPropertyClassName || null === $var->objectPropertyName) {
            return;
        }
        $object = $context->type->object;
        assert($object instanceof Object_);
        $resolved = $object->resolvePropertySlot($var->objectPropertyClassName, $var->objectPropertyName);
        if (null === $resolved) {
            return;
        }
        [$classId, $slotIndex] = $resolved;
        if (!$object->propertySlotRequiresTypedInitGuard($classId, $slotIndex)) {
            return;
        }
        $valuePtr = self::valuePtrFromVariable($context, $var);
        if (null === $valuePtr) {
            return;
        }
        $declaringClass = $object->instancePropertyDeclaringClassName($classId, (string) $var->objectPropertyName);
        $allowsNull = $object->propertySlotAllowsNull($classId, $slotIndex);

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $checkBlock = $fn->appendBasicBlock('typed_prop_byref_check');
        $okBlock = $fn->appendBasicBlock('typed_prop_byref_ok');
        $exitBlock = $fn->appendBasicBlock('typed_prop_byref_exit');
        $raiseBlock = $allowsNull ? null : $fn->appendBasicBlock('typed_prop_byref_raise');
        $initNullBlock = $allowsNull ? $fn->appendBasicBlock('typed_prop_byref_init_null') : null;

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($checkBlock);

        $context->builder->positionAtEnd($checkBlock);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        if ($allowsNull) {
            assert(null !== $initNullBlock);
            $context->builder->branchIf($isUndef, $initNullBlock, $okBlock);

            $context->builder->positionAtEnd($initNullBlock);
            $context->builder->store(
                $i8->constInt(VmVariable::TYPE_NULL, false),
                $context->builder->structGep($valuePtr, $map['type'])
            );
            $context->builder->branch($okBlock);
        } else {
            assert(null !== $raiseBlock);
            $context->builder->branchIf($isUndef, $raiseBlock, $okBlock);

            $context->builder->positionAtEnd($raiseBlock);
            $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                ErrorRaise::ensureStandaloneBodies($context);
            }
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            ErrorRaise::emitRaise(
                $context,
                sprintf(
                    'Cannot access uninitialized non-nullable property %s::$%s by reference',
                    MethodVisibility::formatAnonymousScopeForMessage((string) $declaringClass),
                    $var->objectPropertyName
                )
            );
            if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
            }
            self::emitRaiseAndTerminate($context);
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    /**
     * FETCH_DIM_W on a typed property: array-containing types auto-init [] (zend_try_array_init, #31770).
     * Other typed slots still Error on uninitialized access.
     */
    public static function emitBeforeDimWrite(Context $context, Variable $var): void
    {
        $m5 = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        if ('1' === $m5 || 'true' === strtolower((string) $m5)) {
            return;
        }
        if (Variable::TYPE_VALUE !== $var->type) {
            return;
        }
        if (null === $var->objectPropertyClassName || null === $var->objectPropertyName) {
            return;
        }
        $object = $context->type->object;
        assert($object instanceof Object_);
        $resolved = $object->resolvePropertySlot($var->objectPropertyClassName, $var->objectPropertyName);
        if (null === $resolved) {
            return;
        }
        [$classId, $slotIndex] = $resolved;
        if (!$object->propertySlotRequiresTypedInitGuard($classId, $slotIndex)) {
            return;
        }
        if (!$object->propertySlotAllowsArray($classId, $slotIndex)) {
            self::emitBeforeRead($context, $var);

            return;
        }
        $valuePtr = self::valuePtrFromVariable($context, $var);
        if (null === $valuePtr) {
            return;
        }
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }
        $checkBlock = $fn->appendBasicBlock('typed_prop_dimw_check');
        $initBlock = $fn->appendBasicBlock('typed_prop_dimw_init');
        $okBlock = $fn->appendBasicBlock('typed_prop_dimw_ok');
        $exitBlock = $fn->appendBasicBlock('typed_prop_dimw_exit');
        $context->builder->positionAtEnd($entry);
        $context->builder->branch($checkBlock);
        $context->builder->positionAtEnd($checkBlock);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $context->builder->branchIf($isUndef, $initBlock, $okBlock);
        $context->builder->positionAtEnd($initBlock);
        HashTableHelper::initArray($context, $var);
        $context->builder->branch($okBlock);
        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    /**
     * Uninitialized static typed property read guard (#4908, #5047, zend_object_handlers.c).
     */
    public static function emitBeforeStaticRead(
        Context $context,
        \PHPLLVM\Value $initGlobal,
        string $declaringClass,
        string $propertyName
    ): void {
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $checkBlock = $fn->appendBasicBlock('static_typed_prop_uninit_check');
        $okBlock = $fn->appendBasicBlock('static_typed_prop_uninit_ok');
        $exitBlock = $fn->appendBasicBlock('static_typed_prop_uninit_exit');
        $raiseBlock = $fn->appendBasicBlock('static_typed_prop_uninit_raise');

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($checkBlock);

        $context->builder->positionAtEnd($checkBlock);
        $initFlag = $context->builder->load($initGlobal);
        $isInit = $context->builder->icmp(
            Builder::INT_EQ,
            $initFlag,
            $context->getTypeFromString('int1')->constInt(1, false)
        );
        $context->builder->branchIf($isInit, $okBlock, $raiseBlock);

        $context->builder->positionAtEnd($raiseBlock);
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ErrorRaise::ensureStandaloneBodies($context);
        }
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        ErrorRaise::emitRaise(
            $context,
            sprintf(
                'Typed static property %s::$%s must not be accessed before initialization',
                MethodVisibility::formatAnonymousScopeForMessage($declaringClass),
                $propertyName
            )
        );
        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
        }
        self::emitRaiseAndTerminate($context);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    /** Pending Error is thrown when the JIT function returns ({@see Func\JIT::execute}). */
    private static function emitRaiseAndTerminate(Context $context): void
    {
        self::emitUnreachableFunctionReturn($context);
    }

    private static function emitUnreachableFunctionReturn(Context $context): void
    {
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        if (BasicBlockHelper::isVoidLlvmFunctionValue($fn)) {
            $context->builder->returnVoid();

            return;
        }
        $expected = self::expectedReturnCallbackType($context, $fn);
        $fnType = BasicBlockHelper::llvmFunctionSignatureType($fn);
        $retTy = null !== $fnType
            ? $fnType->getReturnType()
            : $context->getTypeFromString($expected ?? '__value__');
        $context->builder->returnValue(
            self::defaultReturnForType($context, $expected ?? '__value__', $retTy)
        );
    }

    private static function expectedReturnCallbackType(Context $context, \PHPLLVM\Value $fn): ?string
    {
        if (null !== $context->activeFunction) {
            $active = $context->functionReturnType[strtolower($context->activeFunction)] ?? null;
            if (null !== $active) {
                return $active;
            }
        }
        $fnId = spl_object_id($fn);
        foreach ($context->functions as $name => $registered) {
            if (spl_object_id($registered) !== $fnId) {
                continue;
            }

            return $context->functionReturnType[strtolower((string) $name)] ?? null;
        }

        return null;
    }

    private static function defaultReturnForType(Context $context, string $typeName, \PHPLLVM\Type $retTy): \PHPLLVM\Value
    {
        return match ($typeName) {
            'int1', 'bool' => $context->getTypeFromString('int1')->constInt(0, false),
            'double' => $context->getTypeFromString('double')->constReal(0.0),
            'int64', 'long long' => $context->getTypeFromString('int64')->constInt(0, false),
            '__value__' => $retTy->constNull(),
            default => $retTy->getKind() === \PHPLLVM\Type::KIND_POINTER
                ? $retTy->constNull()
                : $retTy->constNull(),
        };
    }

    private static function valuePtrFromVariable(Context $context, Variable $var): ?\PHPLLVM\Value
    {
        if (null !== $var->valueBoxAliasPtr) {
            return JitValueBox::normalizeValuePtr($context, $var->valueBoxAliasPtr);
        }
        if (Variable::KIND_VARIABLE === $var->kind) {
            return JitValueBox::pointer($context, $var->value);
        }

        return null;
    }
}
