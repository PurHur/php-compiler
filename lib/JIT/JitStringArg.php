<?php

declare(strict_types=1);

/**
 * Lower string/path builtin arguments to {@see __string__*} for LLVM calls.
 *
 * Self-host bundle code often passes concat/__DIR__ paths as boxed {@see Variable::TYPE_VALUE}
 * (string tag) rather than {@see Variable::TYPE_STRING}.
 */

namespace PHPCompiler\JIT;

use PHPCompiler\VM\PropertyNameSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStringArg
{
    /** @return Value */
    public static function lower(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        if (\in_array($arg->type, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::TYPE_NATIVE_BOOL,
        ], true)) {
            $coerced = JitNativeString::coerce($context, $arg);

            return $context->helper->loadValue($coerced);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::stringPtrFromVariable($context, $arg);
        }
        $literal = self::compileTimeLiteral($arg);
        if (null !== $literal) {
            if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VALUE === $arg->kind) {
                return self::stringPtrFromVariable($context, $arg);
            }
            // Slot-backed concat temps carry compileTimeString for other folds but must
            // read the runtime __string__* written by JitStringConcat (AOT tier-2, #15642).
            // Use stringPtrFromVariable — NOT materializeStringSlot/__string__separate:
            // separate on a freshly loaded slot pointer corrupts AOT heaps and later
            // segfaults in __value__readObject (sodium AEAD #27318, gzcompress/bin2hex
            // variable args, secretbox keys).
            if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VARIABLE === $arg->kind) {
                return self::stringPtrFromVariable($context, $arg);
            }

            return $context->builder->load($context->constantStringFromString($literal));
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return self::stringPtrFromVariable($context, $arg);
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            // FuncCall->args and similar may be lowered as hashtable pointers (issue #816).
            $ht = $context->helper->loadValue($arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $context->builder->pointerCast(
                    $ht,
                    $context->getTypeFromString('__value__*')
                )
            );
        }

        throw new \LogicException("{$contextLabel} must be a string in this compiler build");
    }

    /**
     * Read {@see __string__*} from a JIT variable that may store a native string or boxed {@see __value__}.
     */
    public static function stringPtrFromVariable(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }
        if (Variable::KIND_VALUE === $arg->kind) {
            $llvmType = $context->getStringFromType($arg->value->typeOf());
            if ('__value__*' === $llvmType) {
                return $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    $arg->value
                );
            }
            if ('__value__' === $llvmType) {
                return $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    JitValueBox::pointer($context, $arg->value)
                );
            }
            if (self::isStringPtrPtrType($llvmType)) {
                return $context->builder->load($arg->value);
            }

            return $arg->value;
        }
        if (Variable::KIND_VARIABLE === $arg->kind) {
            $llvmType = $context->getStringFromType($arg->value->typeOf());
            if ('__value__' === $llvmType) {
                return $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    JitValueBox::pointer($context, $arg->value)
                );
            }
            if ('__value__*' === $llvmType) {
                return $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    $context->builder->load($arg->value)
                );
            }
            if (self::isStringPtrPtrType($llvmType)) {
                return $context->builder->load($arg->value);
            }
        }

        return $context->helper->loadValue($arg);
    }

    /**
     * Lower to {@see __string__*} with an entry-block scratch slot so uses dominate CFG branches (#4066).
     *
     * @return Value
     */
    public static function lowerDominating(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        // Slot-backed locals may receive different ?: arm values; compileTimeString from the
        // first arm must not fold into a shared merge echo (standalone AOT — #15704).
        // Load the slot directly — __string__separate materialize segfaults under thin AOT (#27318).
        if (Variable::KIND_VARIABLE === $arg->kind && Variable::TYPE_STRING === $arg->type) {
            return self::stringPtrFromVariable($context, $arg);
        }
        $literal = self::compileTimeLiteral($arg);
        if (null !== $literal) {
            return $context->builder->load($context->constantStringFromString($literal));
        }
        if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VARIABLE === $arg->kind) {
            return self::stringPtrFromVariable($context, $arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );

            return self::materializeStringSlot($context, $str);
        }

        return self::lower($context, $arg, $contextLabel);
    }

    /** @return Value owning {@see __string__*} in an entry alloca */
    public static function materializeStringDominating(Context $context, Value $sourceStr): Value
    {
        return self::materializeStringSlot($context, $sourceStr);
    }

    /** @return Value owning {@see __string__*} in an entry alloca */
    private static function materializeStringSlot(Context $context, Value $sourceStr): Value
    {
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $sourceStr
        );
        $context->builder->store($owned, $slot);

        return $context->builder->load($slot);
    }

    public static function compileTimeLiteral(Variable $arg): ?string
    {
        // Object temps stash the class name in compileTimeString (#26872) — that is not a
        // string literal (json_encode would fold to "\"stdClass\"" — #28638).
        if (Variable::TYPE_OBJECT === $arg->type) {
            return null;
        }

        return $arg->compileTimeString ?? null;
    }

    /**
     * Lower dynamic property / variable name with zend_operators.c Error on enum/object (#6206).
     *
     * @return Value
     */
    public static function lowerPropertyName(Context $context, Variable $arg): Value
    {
        $literal = self::compileTimeLiteral($arg);
        if (null !== $literal) {
            if (PropertyNameSupport::hasLeadingNullByte($literal)) {
                Builtin\ErrorRaise::ensureLinked($context);
                Builtin\ErrorRaise::emitRaise($context, PropertyNameSupport::LEADING_NULL_BYTE_MESSAGE);

                return $context->builder->load($context->constantStringFromString(''));
            }

            return $context->builder->load($context->constantStringFromString($literal));
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            $classHint = ltrim((string) ($arg->type?->userType ?? ''), '\\');
            if (
                '' !== $classHint
                && 'object' !== strtolower($classHint)
                && $context->type->object->isEnumClassLc(strtolower($classHint))
            ) {
                Builtin\ErrorRaise::ensureLinked($context);
                Builtin\ErrorRaise::emitRaise(
                    $context,
                    'Object of class '.$classHint.' could not be converted to string'
                );

                return $context->builder->load($context->constantStringFromString(''));
            }
            $magic = MagicMethodDispatch::coerceObjectToString($context, $arg);
            if (null !== $magic) {
                $str = self::materializeStringSlot($context, $context->helper->loadValue($magic));
                self::emitLeadingNullBytePropertyNameGuard($context, $str);

                return $str;
            }
            Builtin\ErrorRaise::ensureLinked($context);
            Builtin\ErrorRaise::emitRaise(
                $context,
                'Object of class '.('' !== $classHint ? $classHint : 'stdClass').' could not be converted to string'
            );

            return $context->builder->load($context->constantStringFromString(''));
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $str = self::lowerBoxedPropertyName($context, $arg);
            self::emitLeadingNullBytePropertyNameGuard($context, $str);

            return $str;
        }

        $str = self::lowerDominating($context, $arg, 'dynamic property name');
        self::emitLeadingNullBytePropertyNameGuard($context, $str);

        return $str;
    }

    /**
     * Runtime guard for property names with leading null byte (#5136, zend_verify_property_name).
     */
    private static function emitLeadingNullBytePropertyNameGuard(Context $context, Value $strPtr): void
    {
        Builtin\ErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $hasLen = $context->builder->icmp(Builder::INT_SGT, $len, $i64->constInt(0, false));
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $checkFirst = $fn->appendBasicBlock('prop_name_leading_null_check');
        $okBlock = $fn->appendBasicBlock('prop_name_leading_null_ok');
        $rejectBlock = $fn->appendBasicBlock('prop_name_leading_null_reject');
        $doneBlock = $fn->appendBasicBlock('prop_name_leading_null_done');
        $context->builder->branchIf($hasLen, $checkFirst, $okBlock);
        $context->builder->positionAtEnd($checkFirst);
        $valuePtr = $context->builder->load($context->builder->structGep($strPtr, $map['value']));
        $valuePtr = $context->builder->pointerCast($valuePtr, $i8p);
        $firstByte = $context->builder->load($valuePtr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $context->builder->branchIf($isNull, $rejectBlock, $okBlock);
        $context->builder->positionAtEnd($rejectBlock);
        Builtin\ErrorRaise::emitRaise($context, PropertyNameSupport::LEADING_NULL_BYTE_MESSAGE);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }

    /** @return Value */
    private static function lowerBoxedPropertyName(Context $context, Variable $arg): Value
    {
        Builtin\ErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $rejectBlock = BasicBlockHelper::append($context, 'prop_name_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'prop_name_coerce');
        $doneBlock = BasicBlockHelper::append($context, 'prop_name_done');
        $strSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));

        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        Builtin\ErrorRaise::emitRaise(
            $context,
            'Object of class '.self::compileTimeObjectGivenLabel($context, $arg).' could not be converted to string'
        );
        $context->builder->store(
            $context->builder->load($context->constantStringFromString('')),
            $strSlot
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($coerceBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $context->builder->store($str, $strSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($strSlot);
    }

    private static function compileTimeObjectGivenLabel(Context $context, Variable $arg): string
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }

    /** LLVM may suffix struct names (`__string__.12**`) when parsing helper bitcode. */
    public static function isStringPtrPtrType(string $llvmType): bool
    {
        return '__string__**' === $llvmType || str_ends_with($llvmType, '__string__**');
    }
}
