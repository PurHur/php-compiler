<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPropertyExists;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT helper for property_exists() via PropertyExistsJitHelper PHP (#1372, #16442). */
final class JitPropertyExists
{
    private const TYPE_ERROR =
        'property_exists(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    public static function invoke(Context $context, JITVariable $objectOrClass, JITVariable $propertyArg): Value
    {
        $propLiteral = JitStringArg::compileTimeLiteral($propertyArg);
        if (JITVariable::TYPE_OBJECT === $objectOrClass->type) {
            return self::forObject($context, $objectOrClass, $propertyArg, $propLiteral);
        }
        if (JITVariable::TYPE_VALUE === $objectOrClass->type) {
            // Boxed locals (`$n = 'C'`) carry compileTimeString; fold like method_exists (#32701).
            $classLit = JitStringArg::compileTimeLiteral($objectOrClass)
                ?? $objectOrClass->compileTimeString;
            if (\is_string($classLit) && '' !== $classLit && null !== $propLiteral) {
                if ($context->type->object->hasUserDeclaredClass($classLit)) {
                    return ReflectionBuiltinHelper::propertyExistsLiteral(
                        $context,
                        $classLit,
                        $propLiteral
                    );
                }

                return self::routeThroughPhpHelper($context, $objectOrClass, $propertyArg);
            }

            return self::invokeFromValueBox($context, $objectOrClass, $propertyArg, $propLiteral);
        }
        if (JITVariable::TYPE_STRING !== $objectOrClass->type) {
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($objectOrClass->type));
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass)
            ?? $objectOrClass->compileTimeString;
        if (null !== $classLiteral && '' !== $classLiteral) {
            $propLiteral = JitStringArg::compileTimeLiteral($propertyArg);
            if (
                null !== $propLiteral
                && $context->type->object->hasUserDeclaredClass($classLiteral)
            ) {
                // Same-unit class: LLVM object table (static props included). NestedJIT
                // VmReflection misses AOT classes and returned false (#31966). Autoload for
                // not-yet-loaded names still uses the runtime helper (#26407).
                return ReflectionBuiltinHelper::propertyExistsLiteral(
                    $context,
                    $classLiteral,
                    $propLiteral
                );
            }
            // Runtime helper — autoloads like zend_lookup_class (#26407). Compile-time
            // fold would skip registered autoloaders for not-yet-loaded class strings.
            return self::routeThroughPhpHelper($context, $objectOrClass, $propertyArg);
        }
        // Typed `string $n`: NestedJIT misses AOT classes (#31966). Walk the LLVM object
        // table like method_exists (#32701 leftover) when the property name is a literal.
        $propLiteral = JitStringArg::compileTimeLiteral($propertyArg);
        if (null !== $propLiteral) {
            $classStr = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $objectOrClass,
                    'property_exists',
                    0,
                    'object_or_class'
                )
                : JitStringBuiltinArg::lowerZparamStr(
                    $context,
                    $objectOrClass,
                    'property_exists',
                    0,
                    'object_or_class'
                );

            return JitPropertyExistsObject::existsForRuntimeClassNameLiteralProperty(
                $context,
                $classStr,
                $objectOrClass,
                $propertyArg,
                $propLiteral
            );
        }

        return self::routeThroughPhpHelper($context, $objectOrClass, $propertyArg);
    }

    private static function invokeFromValueBox(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $propertyArg,
        ?string $propLiteral
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $objectOrClass);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        // Value-box tags may include IS_REFCOUNTED; compare the low 7 bits (#27108, #32688).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
        );
        $isEnum = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_ENUM_CASE & 0x7f, false)
        );
        $isObject = $context->builder->or($isObject, $isEnum);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)
        );

        $nullBlock = BasicBlockHelper::append($context, 'prop_exists_null');
        $notNull = BasicBlockHelper::append($context, 'prop_exists_not_null');
        $objectBlock = BasicBlockHelper::append($context, 'prop_exists_obj');
        $notObject = BasicBlockHelper::append($context, 'prop_exists_not_obj');
        $stringBlock = BasicBlockHelper::append($context, 'prop_exists_str');
        $errBlock = BasicBlockHelper::append($context, 'prop_exists_err');
        $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_merge');
        $i1 = $context->getTypeFromString('int1');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);

        $context->builder->branchIf($isNull, $nullBlock, $notNull);

        $context->builder->positionAtEnd($nullBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'null'));

        $context->builder->positionAtEnd($notNull);
        $context->builder->branchIf($isObject, $objectBlock, $notObject);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        $objResult = self::forObject($context, $objVar, $propertyArg, $propLiteral);
        $context->builder->store($objResult, $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notObject);
        $context->builder->branchIf($isString, $stringBlock, $errBlock);

        $context->builder->positionAtEnd($stringBlock);
        // Runtime class string in a value box — table walk when property is a literal (#35788).
        if (null !== $propLiteral) {
            $classStr = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $valuePtr
            );
            $strResult = JitPropertyExistsObject::existsForRuntimeClassNameLiteralProperty(
                $context,
                $classStr,
                $objectOrClass,
                $propertyArg,
                $propLiteral
            );
        } else {
            $strResult = self::routeThroughPhpHelper($context, $objectOrClass, $propertyArg);
        }
        $context->builder->store($strResult, $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitBoxedScalarTypeErrorAndAbort($context, $kind);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }

    /** Map value-box type tag to Zend given-type label (Z_PARAM_OBJ_OR_STR) (#33054 leftover). */
    private static function emitBoxedScalarTypeErrorAndAbort(Context $context, Value $kind): void
    {
        $i8 = $context->getTypeFromString('int8');
        $intBlock = BasicBlockHelper::append($context, 'prop_exists_te_int');
        $afterInt = BasicBlockHelper::append($context, 'prop_exists_te_after_int');
        $boolBlock = BasicBlockHelper::append($context, 'prop_exists_te_bool');
        $afterBool = BasicBlockHelper::append($context, 'prop_exists_te_after_bool');
        $floatBlock = BasicBlockHelper::append($context, 'prop_exists_te_float');
        $mixedBlock = BasicBlockHelper::append($context, 'prop_exists_te_mixed');

        // Value-box tags use JIT TYPE_NATIVE_* (bool=2, double=3) — not VM TYPE_BOOLEAN.
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        $context->builder->branchIf($isInt, $intBlock, $afterInt);
        $context->builder->positionAtEnd($intBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'int'));

        $context->builder->positionAtEnd($afterInt);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL & 0x7f, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);
        $context->builder->positionAtEnd($boolBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'bool'));

        $context->builder->positionAtEnd($afterBool);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE & 0x7f, false)
        );
        $context->builder->branchIf($isFloat, $floatBlock, $mixedBlock);
        $context->builder->positionAtEnd($floatBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'float'));

        $context->builder->positionAtEnd($mixedBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));
    }

    public static function routeThroughPhpHelper(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $propertyArg
    ): Value {
        $operandPtr = JitValueBox::valuePtrFromVariable($context, $objectOrClass);
        $propertyStr = self::jitPropertyNameArg($context, $propertyArg);

        return StringPropertyExists::invoke($context, $operandPtr, $propertyStr);
    }

    public static function routeObjectThroughPhpHelper(
        Context $context,
        JITVariable $objectArg,
        JITVariable $propertyArg
    ): Value {
        $obj = $context->helper->loadValue($objectArg);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );
        $propertyStr = self::jitPropertyNameArg($context, $propertyArg);

        return StringPropertyExists::invoke($context, $ptr, $propertyStr);
    }

    private static function jitPropertyNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'property_exists',
                1,
                'property'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'property_exists',
            1,
            'property'
        );
    }

    /**
     * Catchable under AOT try/catch; fatal when uncaught (#33054 / #27447).
     * Bare pending-raise + libc abort() SIGABRTs inside try — use ExceptionBridge.
     */
    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'prop_exists_te_cont');
    }

    private static function scalarTypeError(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return \sprintf(self::TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::TYPE_ERROR, 'bool');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
    }

    private static function forObject(
        Context $context,
        JITVariable $objectArg,
        JITVariable $propertyArg,
        ?string $propLiteral
    ): Value {
        $objMap = $context->structFieldMap['__object__'];
        $obj = $context->helper->loadValue($objectArg);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        // __PHP_Incomplete_Class — route through PHP helper so property_exists warns + false (#26366).
        $incompleteId = $context->type->object->classIdByName('__PHP_Incomplete_Class');
        if (null === $incompleteId) {
            $incompleteId = $context->type->object->classIdForLowerName('__php_incomplete_class');
        }
        if (null !== $incompleteId) {
            $isIncomplete = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($incompleteId, 'int64')
            );
            $incBlock = BasicBlockHelper::append($context, 'prop_exists_incomplete');
            $normBlock = BasicBlockHelper::append($context, 'prop_exists_complete');
            $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_obj_merge');
            $context->builder->branchIf($isIncomplete, $incBlock, $normBlock);

            $context->builder->positionAtEnd($incBlock);
            $incResult = self::routeObjectThroughPhpHelper($context, $objectArg, $propertyArg);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($normBlock);
            $normResult = JitPropertyExistsObject::forCompleteObject(
                $context,
                $objectArg,
                $propertyArg,
                $propLiteral,
                $classId
            );
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($mergeBlock);
            $phi = $context->builder->phi($incResult->typeOf());
            $phi->addIncoming($incResult, $incBlock);
            $phi->addIncoming($normResult, $normBlock);

            return $phi;
        }

        return JitPropertyExistsObject::forCompleteObject(
            $context,
            $objectArg,
            $propertyArg,
            $propLiteral,
            $classId
        );
    }
}
