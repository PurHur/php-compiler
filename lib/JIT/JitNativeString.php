<?php

declare(strict_types=1);

/**
 * Coerce native JIT scalars to {@see __string__*} for concatenation.
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitNativeString
{
    private static int $coerceResumeSerial = 0;

    /** Reposition when ensureLinked cleared LLVM insertion before boxed strval (#1492). */
    public static function ensureInsertBlock(Context $context): void
    {
        if (null !== BasicBlockHelper::tryGetInsertBlock($context)) {
            return;
        }
        $fn = BasicBlockHelper::parentFunction($context);
        $resume = $fn->appendBasicBlock('jit_native_string_resume_'.(++self::$coerceResumeSerial));
        $context->builder->positionAtEnd($resume);
    }

    public static function coerce(
        Context $context,
        Variable $var,
        ?Operand $sourceOperand = null,
        ?string $classHint = null
    ): Variable {
        if (Variable::TYPE_STRING === $var->type) {
            return $var;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            // Operand PHPTypes userType — Variable::$type is an int, so echo/cast must pass the hint (#26821).
            if (null === $classHint || '' === $classHint) {
                $fromOp = $sourceOperand?->type?->userType ?? null;
                if (\is_string($fromOp) && '' !== ltrim($fromOp, '\\')) {
                    $classHint = $fromOp;
                }
            }
            if (
                (null === $classHint || '' === $classHint || 'object' === strtolower((string) $classHint)
                    || 'unknown' === strtolower((string) $classHint))
                && null !== ($var->magicGetOverloadedClass ?? null)
                && '' !== $var->magicGetOverloadedClass
            ) {
                $classHint = $var->magicGetOverloadedClass;
            }
            $classHint = null !== $classHint ? ltrim($classHint, '\\') : null;
            // Resolve class hint before SXE fold — baked SXE slots must not run on plain objects (#28646).
            $sxeFold = \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::tryFoldStringCast(
                $context,
                $var,
                $classHint
            );
            if (null !== $sxeFold) {
                return $sxeFold;
            }
            $magic = MagicMethodDispatch::coerceObjectToString(
                $context,
                $var,
                (null !== $classHint && '' !== $classHint && 'object' !== strtolower($classHint))
                    ? $classHint
                    : null
            );
            if (null !== $magic) {
                return $magic;
            }
            // Catch temps often lack a compile-time class hint — try Throwable::__toString (#26796).
            $isThrowable = ReflectionBuiltinHelper::emitInstanceOf($context, $var, 'Throwable');
            $isBool = Variable::TYPE_NATIVE_BOOL === $isThrowable->type
                ? $isThrowable->value
                : $context->helper->loadValue($isThrowable);
            $yesBb = BasicBlockHelper::append($context, 'jit_cast_throwable_yes');
            $noBb = BasicBlockHelper::append($context, 'jit_cast_throwable_no');
            $joinBb = BasicBlockHelper::append($context, 'jit_cast_throwable_join');
            $context->builder->branchIf($isBool, $yesBb, $noBb);
            $context->builder->positionAtEnd($yesBb);
            $toCall = $context->resolveFunctionProxy('exception::__tostring');
            $raw = $toCall->call($context, $var);
            $strPtr = (new \PHPCompiler\ext\standard\strval())->valueToString(
                $context,
                JitValueBox::coerceToValuePtrForStore($context, $raw)
            );
            $yesEnd = $context->builder->getInsertBlock();
            $context->builder->branch($joinBb);
            $context->builder->positionAtEnd($noBb);
            $hint = $classHint ?? '';
            if ('' !== $hint && 'object' !== strtolower($hint)) {
                // Zend zend_std_cast_object_tostring — Error when no __toString (#26821).
                Builtin\ErrorRaise::ensureLinked($context);
                Builtin\ErrorRaise::emitRaise(
                    $context,
                    \PHPCompiler\VM\ValueEchoSupport::objectToStringErrorMessage($hint)
                );
            }
            $empty = $context->builder->load($context->constantStringFromString(''));
            $noEnd = $context->builder->getInsertBlock();
            $context->builder->branch($joinBb);
            $context->builder->positionAtEnd($joinBb);
            $phi = $context->builder->phi($strPtr->typeOf());
            $phi->addIncoming($strPtr, $yesEnd);
            $phi->addIncoming($empty, $noEnd);

            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $phi
            );
        }
        if (Variable::TYPE_VALUE === $var->type) {
            self::ensureInsertBlock($context);
            // Named locals are often value-boxed objects; strval() does not call __toString (#26821).
            if (null === $classHint || '' === $classHint) {
                $fromOp = $sourceOperand?->type?->userType ?? null;
                if (\is_string($fromOp) && '' !== ltrim($fromOp, '\\')) {
                    $classHint = ltrim($fromOp, '\\');
                }
            } else {
                $classHint = ltrim($classHint, '\\');
            }
            if (
                (null === $classHint || '' === $classHint || 'object' === strtolower((string) $classHint)
                    || 'unknown' === strtolower((string) $classHint))
                && null !== ($var->magicGetOverloadedClass ?? null)
                && '' !== $var->magicGetOverloadedClass
            ) {
                $classHint = ltrim((string) $var->magicGetOverloadedClass, '\\');
            }
            // Class hint before SXE fold — same #28646 guard as TYPE_OBJECT.
            $sxeFold = \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::tryFoldStringCast(
                $context,
                $var,
                $classHint
            );
            if (null !== $sxeFold) {
                return $sxeFold;
            }
            // Folded BcMath\Number method results carry value/scale metadata (#26803).
            $bcCt = $var->compileTimeBcmathNumber ?? null;
            if (null !== $bcCt && \PHPCompiler\CompilerVersion::supportsBcmath()) {
                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($bcCt['value']))
                );
            }
            if (
                null !== $classHint
                && '' !== $classHint
                && 'object' !== strtolower($classHint)
            ) {
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
                $objPtr = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    $valuePtr
                );
                $objVar = new Variable(
                    $context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $objPtr
                );
                // SXE foreach values: read baked text (cast handler, not only __toString) (#34543).
                if (\PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::valueBoxMayBeSimpleXmlElement(
                    $context,
                    $classHint
                ) && 'simplexmlelement' === strtolower($classHint)) {
                    $sxeStr = \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::readBakedTextFromObjectPtr(
                        $context,
                        $objPtr
                    );

                    return new Variable(
                        $context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        $sxeStr
                    );
                }
                $magic = MagicMethodDispatch::coerceObjectToString($context, $objVar, $classHint);
                if (null !== $magic) {
                    return $magic;
                }
                Builtin\ErrorRaise::ensureLinked($context);
                Builtin\ErrorRaise::emitRaise(
                    $context,
                    \PHPCompiler\VM\ValueEchoSupport::objectToStringErrorMessage($classHint)
                );

                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString(''))
                );
            }

            // Value-boxed objects without a class hint: BcMath\Number (#24683 / #26803) and
            // SimpleXMLElement foreach elements (#34543 / re-#27535). strval() on object kind
            // SIGSEGVs — probe class_id after a kind check (plain strings stay on strval; #28625).
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
            $map = $context->structFieldMap['__value__'];
            $kindRaw = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));
            $i8 = $context->getTypeFromString('int8');
            $isObj = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->and($kindRaw, $i8->constInt(0x7f, false)),
                $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
            );
            $yesKind = BasicBlockHelper::append($context, 'cast_vbox_obj_yes');
            $noKind = BasicBlockHelper::append($context, 'cast_vbox_obj_no');
            $join = BasicBlockHelper::append($context, 'cast_vbox_obj_join');
            $context->builder->branchIf($isObj, $yesKind, $noKind);

            $context->builder->positionAtEnd($yesKind);
            $objPtr = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $objMap = $context->structFieldMap['__object__'];
            $classIdVal = $context->builder->load($context->builder->structGep($objPtr, $objMap['class_id']));
            $i64 = $context->getTypeFromString('int64');
            $objVar = new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $objPtr
            );

            $sxeId = \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::simpleXmlElementClassId($context);
            $isSxe = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($sxeId, false)
            );
            $yesSxe = BasicBlockHelper::append($context, 'cast_vbox_sxe_yes');
            $notSxe = BasicBlockHelper::append($context, 'cast_vbox_sxe_no');
            $context->builder->branchIf($isSxe, $yesSxe, $notSxe);

            $context->builder->positionAtEnd($yesSxe);
            $sxeStr = \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript::readBakedTextFromObjectPtr(
                $context,
                $objPtr
            );
            $sxeEnd = $context->builder->getInsertBlock();
            $context->builder->branch($join);

            $context->builder->positionAtEnd($notSxe);
            $specialStr = null;
            $specialEnd = null;
            if (
                \PHPCompiler\CompilerVersion::supportsBcmath()
                && $context->functionIsRegistered('bcmath\\number::__tostring')
            ) {
                $numberId = $context->type->object->lookup('bcmath\\number');
                $isNumber = $context->builder->icmp(
                    Builder::INT_EQ,
                    $classIdVal,
                    $i64->constInt($numberId, false)
                );
                $yesNum = BasicBlockHelper::append($context, 'cast_vbox_bcmath_yes');
                $notNum = BasicBlockHelper::append($context, 'cast_vbox_bcmath_no');
                $context->builder->branchIf($isNumber, $yesNum, $notNum);
                $context->builder->positionAtEnd($yesNum);
                $toCall = $context->resolveFunctionProxy('bcmath\\number::__tostring');
                $raw = $toCall->call($context, $objVar);
                $specialStr = (new \PHPCompiler\ext\standard\strval())->valueToString(
                    $context,
                    JitValueBox::coerceToValuePtrForStore($context, $raw)
                );
                $specialEnd = $context->builder->getInsertBlock();
                $context->builder->branch($join);
                $context->builder->positionAtEnd($notNum);
            }
            $objFallback = (new \PHPCompiler\ext\standard\strval())->valueToString(
                $context,
                $valuePtr
            );
            $objFallbackEnd = $context->builder->getInsertBlock();
            $context->builder->branch($join);

            $context->builder->positionAtEnd($noKind);
            $scalarStr = (new \PHPCompiler\ext\standard\strval())->valueToString(
                $context,
                $valuePtr
            );
            $noEnd = $context->builder->getInsertBlock();
            $context->builder->branch($join);

            $context->builder->positionAtEnd($join);
            $phi = $context->builder->phi($sxeStr->typeOf());
            $phi->addIncoming($sxeStr, $sxeEnd);
            if (null !== $specialStr && null !== $specialEnd) {
                $phi->addIncoming($specialStr, $specialEnd);
            }
            $phi->addIncoming($objFallback, $objFallbackEnd);
            $phi->addIncoming($scalarStr, $noEnd);

            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $phi
            );
        }

        $value = $context->helper->loadValue($var);
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    JitResourceIdString::formatNativeLong($context, $value, $sourceOperand)
                );
            case Variable::TYPE_NATIVE_DOUBLE:
                // PG(precision) via VmZendDoubleString (#21963, Zend/zend_operators.c);
                // libc %g → zend_gcvt E-form (#32316).
                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    Builtin\ZendDoubleStringRuntime::formatGcvt($context, $value)
                );
            case Variable::TYPE_NATIVE_BOOL:
                self::ensureInsertBlock($context);
                $trueBlock = BasicBlockHelper::append($context, 'coerce_bool_true');
                $falseBlock = BasicBlockHelper::append($context, 'coerce_bool_false');
                $endBlock = BasicBlockHelper::append($context, 'coerce_bool_end');
                $context->builder->branchIf($value, $trueBlock, $falseBlock);
                $context->builder->positionAtEnd($trueBlock);
                $trueStr = $context->builder->load($context->constantStringFromString('1'));
                $context->builder->branch($endBlock);
                $context->builder->positionAtEnd($falseBlock);
                $falseStr = $context->builder->load($context->constantStringFromString(''));
                $context->builder->branch($endBlock);
                $context->builder->positionAtEnd($endBlock);
                $phi = $context->builder->phi($trueStr->typeOf());
                $phi->addIncoming($trueStr, $trueBlock);
                $phi->addIncoming($falseStr, $falseBlock);

                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $phi
                );
            default:
                throw new \LogicException(
                    'Cannot coerce JIT type '.Variable::getStringType($var->type).' to string for concat'
                );
        }
    }

    /** Decimal string for a packed-list index (array_merge numeric-string keys; #3607). */
    public static function formatIndexKey(Context $context, Value $indexI64): Value
    {
        $sizeT = $context->getTypeFromString('size_t');

        return self::format(
            $context,
            $context->builder->truncOrBitCast($indexI64, $sizeT),
            '%zu'
        );
    }

    private static function format(Context $context, Value $value, string $format): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString($format),
            $charPtr
        );
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $value
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }
}
