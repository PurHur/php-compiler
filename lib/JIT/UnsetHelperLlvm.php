<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\OpCode;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmUnset;
use PHPLLVM\Builder;

/**
 * LLVM lowering bodies for unset() — delegates semantic guards to {@see VmUnset} (#10238, #23304).
 */
final class UnsetHelperLlvm
{
    public static function compileOffset(
        Context $context,
        Block $block,
        OpCode $op,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $containerOp = $block->getOperand($op->arg2);
        $dimOp = $block->getOperand($op->arg3);
        $container = $context->getVariableFromOp($containerOp);
        $dim = $context->getVariableFromOp($dimOp);
        // php-src DateInterval living unset is a no-op — skip value-box diamond entirely (#26180).
        if ($op->unsetOnProperty && self::shouldNoopDateIntervalUnsetFromOps($containerOp, $dimOp, $block, $context)) {
            return;
        }
        // ZEND_UNSET_OBJ: typed non-objects are a silent no-op (#30065). Value-box receivers
        // still need a runtime object check (object → property unset; else no-op).
        if ($op->unsetOnProperty) {
            if (Variable::TYPE_OBJECT === $container->type) {
                self::compilePropertyUnset($context, $block, $containerOp, $dimOp, $jit);

                return;
            }
            if (Variable::TYPE_VALUE === $container->type) {
                self::compileValueBoxPropertyUnset(
                    $context,
                    $block,
                    $containerOp,
                    $dimOp,
                    $container,
                    $jit
                );

                return;
            }

            return;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            if (ArrayAccessHelper::tryCompileOffsetUnset($context, $container, $dim, $containerOp)) {
                return;
            }
            self::emitCannotUseObjectAsArray($context, $container, $containerOp);

            return;
        }
        if (Variable::TYPE_HASHTABLE === $container->type) {
            HashTableHelper::offsetUnset($context, $container, $dim);

            return;
        }
        // ZEND_UNSET_DIM: null silent no-op; false → Deprecated (leave false); other scalars Error
        // (zend_vm_def.h; #30099).
        if (Variable::TYPE_NULL === $container->type) {
            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $container->type) {
            self::compileNativeBoolUnsetDim($context, $block, $op, $container);

            return;
        }
        if (VmUnset::isScalarJitContainer($container)) {
            self::emitScalarUnsetDimError($context, $container);

            return;
        }
        if (Variable::TYPE_VALUE === $container->type) {
            self::compileValueBoxOffsetUnset(
                $context,
                $block,
                $containerOp,
                $dimOp,
                $container,
                $dim,
                $op,
                $jit
            );

            return;
        }
        throw new \LogicException('unset() offset only supports arrays and objects in this compiler build');
    }

    /**
     * Value-box ZEND_UNSET_OBJ: unset property when runtime type is object; else no-op (#30065).
     */
    private static function compileValueBoxPropertyUnset(
        Context $context,
        Block $block,
        Operand $containerOp,
        Operand $dimOp,
        Variable $container,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'up'.(string) spl_object_id($context);

        $objectBb = BasicBlockHelper::append($context, 'unset_obj_vb_object_'.$tag);
        $noopBb = BasicBlockHelper::append($context, 'unset_obj_vb_noop_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'unset_obj_vb_done_'.$tag);

        $baseType = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $isJitObject = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isObject, $isJitObject),
            $objectBb,
            $noopBb
        );

        $context->builder->positionAtEnd($objectBb);
        self::compilePropertyUnset($context, $block, $containerOp, $dimOp, $jit);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($noopBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * Typed bool container: false → Deprecated only; true → Error (#30099 / zend_vm_def.h).
     */
    private static function compileNativeBoolUnsetDim(
        Context $context,
        Block $block,
        OpCode $op,
        Variable $container
    ): void {
        $boolVal = $context->helper->loadValue($container);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $boolVal->typeOf()->constInt(0, false)
        );
        $errBb = BasicBlockHelper::append($context, 'unset_dim_bool_true_err');
        $falseBb = BasicBlockHelper::append($context, 'unset_dim_bool_false_dep');
        $doneBb = BasicBlockHelper::append($context, 'unset_dim_bool_done');
        $context->builder->branchIf($isTrue, $errBb, $falseBb);

        $context->builder->positionAtEnd($errBb);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::ERROR_NON_ARRAY);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($falseBb);
        $deprecationLine = null !== $op->sourceLocation && $op->sourceLocation->startLine > 0
            ? $op->sourceLocation->startLine
            : 0;
        DynamicPropertyDeprecationGuard::emitFalseToArray(
            $context,
            $block->scriptPath(),
            $deprecationLine
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * unset($obj[$k]) on non-ArrayAccess — Zend Error (DOM collections; #23304).
     */
    private static function emitCannotUseObjectAsArray(
        Context $context,
        Variable $container,
        ?Operand $containerOp
    ): void {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        $displayName = self::objectDisplayNameForError($context, $container, $containerOp);
        if (null !== $displayName) {
            ErrorRaise::emitRaise($context, VmUnset::cannotUseObjectAsArrayMessage($displayName));

            return;
        }
        self::emitCannotUseObjectAsArrayRuntime($context, $container);
    }

    private static function objectDisplayNameForError(
        Context $context,
        Variable $container,
        ?Operand $containerOp
    ): ?string {
        unset($context, $container);
        if (null === $containerOp || null === $containerOp->type || Type::TYPE_OBJECT !== $containerOp->type->type) {
            return null;
        }
        $userType = $containerOp->type->userType ?? '';
        if ('' === $userType || 'object' === strtolower(ltrim($userType, '\\'))) {
            return null;
        }

        return ltrim($userType, '\\');
    }

    private static function emitCannotUseObjectAsArrayRuntime(Context $context, Variable $container): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $container);
        $classCstr = $context->builder->structGep(
            $classNameStr,
            $context->structFieldIndex($classNameStr, 'value')
        );
        $buf = $context->builder->alloca($i8->arrayType(512), 1, 'unset_dim_obj_msg');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $context->constantFromInteger(512, 'size_t'),
            $context->builder->pointerCast(
                $context->constantFromString('Cannot use object of type %s as array'),
                $i8p
            ),
            $classCstr
        );
        $len = $context->builder->zext(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SLT, $written, $i32->constInt(0, true)),
                $i32->constInt(0, false),
                $written
            ),
            $sizeT
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_error'),
            $bufPtr,
            $len
        );
    }

    private static function emitScalarUnsetDimError(Context $context, Variable $container): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::scalarUnsetDimErrorMessage($container->type));
    }

    private static function compileValueBoxOffsetUnset(
        Context $context,
        Block $block,
        Operand $containerOp,
        Operand $dimOp,
        Variable $container,
        Variable $dim,
        OpCode $op,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'u'.(string) spl_object_id($context);

        $arrayBb = BasicBlockHelper::append($context, 'unset_dim_vb_array_'.$tag);
        $objectBb = BasicBlockHelper::append($context, 'unset_dim_vb_object_'.$tag);
        $stringBb = BasicBlockHelper::append($context, 'unset_dim_vb_string_'.$tag);
        $nullBb = BasicBlockHelper::append($context, 'unset_dim_vb_null_'.$tag);
        $boolBb = BasicBlockHelper::append($context, 'unset_dim_vb_bool_'.$tag);
        $scalarBb = BasicBlockHelper::append($context, 'unset_dim_vb_scalar_'.$tag);
        $afterArray = BasicBlockHelper::append($context, 'unset_dim_vb_after_array_'.$tag);
        $afterObject = BasicBlockHelper::append($context, 'unset_dim_vb_after_object_'.$tag);
        $afterString = BasicBlockHelper::append($context, 'unset_dim_vb_after_string_'.$tag);
        $afterNull = BasicBlockHelper::append($context, 'unset_dim_vb_after_null_'.$tag);
        $afterBool = BasicBlockHelper::append($context, 'unset_dim_vb_after_bool_'.$tag);
        // Continue the caller (main) after a successful unset — returnVoid would end the script (#28051 AOT).
        $doneBb = BasicBlockHelper::append($context, 'unset_dim_vb_done_'.$tag);

        // Value-box arrays are tagged TYPE_HASHTABLE (7); VM TYPE_ARRAY (6) also appears.
        $baseType = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isArray = $context->builder->or($isVmArray, $isHt);
        $context->builder->branchIf($isArray, $arrayBb, $afterArray);

        $context->builder->positionAtEnd($arrayBb);
        HashTableHelper::offsetUnset($context, $container, $dim);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterArray);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $isJitObject = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isObject, $isJitObject),
            $objectBb,
            $afterObject
        );

        $context->builder->positionAtEnd($objectBb);
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $context->getTypeFromString('__object__*')->constNull()
        );
        $objVar->value = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        if (ArrayAccessHelper::tryCompileOffsetUnset($context, $objVar, $dim, $containerOp)) {
            $context->builder->branch($doneBb);
        } else {
            self::emitCannotUseObjectAsArray($context, $objVar, $containerOp);
            $context->builder->branch($doneBb);
        }

        $context->builder->positionAtEnd($afterObject);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $isJitString = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isString, $isJitString),
            $stringBb,
            $afterString
        );

        $context->builder->positionAtEnd($stringBb);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::ERROR_STRING_OFFSET);
        $context->builder->branch($doneBb);

        // null / undef — silent no-op (#30099).
        $context->builder->positionAtEnd($afterString);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $afterNull);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        // bool: false → Deprecated; true → Error (#30099).
        $context->builder->positionAtEnd($afterNull);
        $isVmBool = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(VmVariable::TYPE_BOOLEAN, false)
        );
        $isJitBool = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isVmBool, $isJitBool),
            $boolBb,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBb);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $boolErrBb = BasicBlockHelper::append($context, 'unset_dim_vb_bool_true_'.$tag);
        $boolFalseBb = BasicBlockHelper::append($context, 'unset_dim_vb_bool_false_'.$tag);
        $context->builder->branchIf($isTrue, $boolErrBb, $boolFalseBb);

        $context->builder->positionAtEnd($boolErrBb);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::ERROR_NON_ARRAY);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($boolFalseBb);
        $deprecationLine = null !== $op->sourceLocation && $op->sourceLocation->startLine > 0
            ? $op->sourceLocation->startLine
            : 0;
        DynamicPropertyDeprecationGuard::emitFalseToArray(
            $context,
            $block->scriptPath(),
            $deprecationLine
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterBool);
        $context->builder->branch($scalarBb);

        $context->builder->positionAtEnd($scalarBb);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::ERROR_NON_ARRAY);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function compilePropertyUnset(
        Context $context,
        Block $block,
        Operand $containerOp,
        Operand $dimOp,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $operandUserType = Type::TYPE_OBJECT === $containerOp->type->type
            ? $containerOp->type->userType
            : null;
        $blockClassName = null !== $block->func && null !== $block->func->class
            ? $block->func->class->value
            : null;
        $declaringClass = VmUnset::resolveDeclaringClass(
            $operandUserType,
            $blockClassName,
            $context->scope->className
        );
        // php-src DateInterval living fields — unset is a no-op (ext/date/php_date.c; #26180).
        // Skip before loadPropertyReceiver/propertyFetch: NATIVE_LONG←null breaks AOT verify.
        if (self::shouldNoopDateIntervalUnset($declaringClass, $dimOp)) {
            return;
        }
        $receiver = self::loadPropertyReceiver($context, $containerOp);
        $null = new Variable(
            $context,
            Variable::TYPE_NULL,
            Variable::KIND_VALUE,
            $context->getTypeFromString('__value__*')->constNull()
        );
        $null->isNullConstant = true;
        if ($dimOp instanceof Literal) {
            if (PropertyHookDispatch::emitVirtualHookUnsetGuard(
                $context,
                $declaringClass,
                $dimOp->value,
                $jit
            )) {
                return;
            }
            $prop = $context->type->object->propertyFetch($receiver, $declaringClass, $dimOp->value);
            if (null !== $prop->objectPropertySlot && null !== $prop->objectPropertyType) {
                // Match assign order: readonly before asymmetric (#29273 / zend_object_handlers.c).
                DynamicObjectReadonlyGuard::emitBeforePropertyStore(
                    $context,
                    $prop,
                    $context->jitEnclosingBlock,
                    'unset'
                );
                ReadonlyClassGuard::emitBeforePropertyStore(
                    $context,
                    $prop,
                    $context->jitEnclosingBlock,
                    'unset',
                    $jit
                );
                if (
                    null !== $jit
                    && AsymmetricVisibilityGuard::emitBeforePropertyUnset(
                        $context,
                        $jit,
                        $prop,
                        $context->jitEnclosingBlock
                    )
                ) {
                    return;
                }
                ReadonlyClassGuard::emitStoreUnlessPending(
                    $context,
                    static function () use ($context, $prop, $null): void {
                        $context->type->object->propertyStore(
                            $prop->objectPropertySlot,
                            $null,
                            $prop->objectPropertyType
                        );
                    }
                );
            }

            return;
        }
        $nameVar = $context->getVariableFromOp($dimOp);
        $prop = $context->type->object->propertyFetchDynamic($receiver, $declaringClass, $nameVar);
        if (null !== $prop->objectPropertySlot && null !== $prop->objectPropertyType) {
            // Match assign order: readonly before asymmetric (#29273 / zend_object_handlers.c).
            DynamicObjectReadonlyGuard::emitBeforePropertyStore(
                $context,
                $prop,
                $context->jitEnclosingBlock,
                'unset'
            );
            ReadonlyClassGuard::emitBeforePropertyStore(
                $context,
                $prop,
                $context->jitEnclosingBlock,
                'unset',
                $jit
            );
            if (
                null !== $jit
                && AsymmetricVisibilityGuard::emitBeforePropertyUnset(
                    $context,
                    $jit,
                    $prop,
                    $context->jitEnclosingBlock
                )
            ) {
                return;
            }
            ReadonlyClassGuard::emitStoreUnlessPending(
                $context,
                static function () use ($context, $prop, $null): void {
                    $context->type->object->propertyStore(
                        $prop->objectPropertySlot,
                        $null,
                        $prop->objectPropertyType
                    );
                }
            );
        }
    }

    private static function loadPropertyReceiver(Context $context, Operand $objOp): \PHPLLVM\Value
    {
        $var = $context->getVariableFromOp($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $var)
            );
        }

        throw new \LogicException(
            'Property unset receiver must be object or object-valued property, got '
            .Variable::getStringType($var->type)
        );
    }

    /**
     * php-src DateInterval living fields ignore unset (get_property_ptr_ptr → NULL; #26180).
     *
     * Value-box unset loses userType so declaringClass is often "object" — treat living
     * property names as no-ops there too (avoids NATIVE_LONG←null store / verify failure).
     * Typed non-DateInterval classes still take the normal unset path.
     */
    private static function shouldNoopDateIntervalUnset(string $declaringClass, Operand $dimOp): bool
    {
        $lc = strtolower(ltrim($declaringClass, '\\'));
        $knownDi = 'dateinterval' === $lc;
        $unresolved = 'object' === $lc || '' === $lc;
        if ($dimOp instanceof Literal) {
            if (!DateIntervalSupport::isLivingProperty((string) $dimOp->value)) {
                return false;
            }

            return $knownDi || $unresolved;
        }

        // Dynamic unset($i->$p) on typed DateInterval — skip FetchDynamic. Unresolved
        // dynamic keeps the normal path (avoid no-opping all value-box dynamic unsets).
        return $knownDi;
    }

    /** Resolve declaring class from operands for early DateInterval unset no-op (#26180). */
    private static function shouldNoopDateIntervalUnsetFromOps(
        Operand $containerOp,
        Operand $dimOp,
        Block $block,
        Context $context
    ): bool {
        $operandUserType = null !== $containerOp->type && Type::TYPE_OBJECT === $containerOp->type->type
            ? $containerOp->type->userType
            : null;
        $blockClassName = null !== $block->func && null !== $block->func->class
            ? $block->func->class->value
            : null;
        $declaringClass = VmUnset::resolveDeclaringClass(
            $operandUserType,
            $blockClassName,
            $context->scope->className
        );

        return self::shouldNoopDateIntervalUnset($declaringClass, $dimOp);
    }
}
