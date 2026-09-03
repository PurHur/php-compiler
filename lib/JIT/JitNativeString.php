<?php

declare(strict_types=1);

/**
 * Coerce native JIT scalars to {@see __string__*} for concatenation.
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

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
            $sxeFold = $context->extensionLowering->tryFoldSimpleXmlStringCast(
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
            return self::tryCoerceThrowableToString($context, $var, $classHint ?? '');
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
            $sxeFold = $context->extensionLowering->tryFoldSimpleXmlStringCast(
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
                && !$context->type->object->isInterfaceClassLc(strtolower(ltrim($classHint, '\\')))
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
                if ($context->extensionLowering->simpleXmlValueBoxMayBeElement(
                    $context,
                    $classHint
                ) && 'simplexmlelement' === strtolower($classHint)) {
                    $sxeStr = $context->extensionLowering->tryReadSimpleXmlBakedText(
                        $context,
                        $objPtr
                    );
                    if (null !== $sxeStr) {
                        return new Variable(
                            $context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VALUE,
                            $sxeStr
                        );
                    }
                }
                $magic = MagicMethodDispatch::coerceObjectToString($context, $objVar, $classHint);
                $hintLc = strtolower(ltrim($classHint, '\\'));
                if (str_starts_with($hintLc, '?')) {
                    $hintLc = substr($hintLc, 1);
                }
                if (null === $magic && 'reflectiontype' === $hintLc) {
                    foreach (['ReflectionNamedType', 'ReflectionUnionType'] as $concrete) {
                        $magic = MagicMethodDispatch::coerceObjectToString($context, $objVar, $concrete);
                        if (null !== $magic) {
                            break;
                        }
                    }
                }
                if (null !== $magic) {
                    return $magic;
                }

                return self::tryCoerceThrowableToString($context, $objVar, $classHint);
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

            $sxeId = $context->type->object->lookup('SimpleXMLElement');
            $isSxe = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($sxeId, false)
            );
            $yesSxe = BasicBlockHelper::append($context, 'cast_vbox_sxe_yes');
            $notSxe = BasicBlockHelper::append($context, 'cast_vbox_sxe_no');
            $context->builder->branchIf($isSxe, $yesSxe, $notSxe);

            $context->builder->positionAtEnd($yesSxe);
            $sxeStr = $context->extensionLowering->tryReadSimpleXmlBakedText(
                $context,
                $objPtr
            );
            if (null === $sxeStr) {
                $sxeStr = $context->builder->load($context->constantStringFromString(''));
            }
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
            // No compile-time class hint: __toString via runtime class_id (Spl iterators, Reflection*, …).
            // Peer JitUnlikeCompare::objectVsString (#32514); was ReflectionNamedType-only (#26821).
            $objFallback = self::coerceValueBoxObjectByStringableClassId(
                $context,
                $objVar,
                $classIdVal,
                'cast_vbox_stringable_'.spl_object_id($context)
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
                // Loop counters / arithmetic cannot be resource handles — skip snprintf
                // malloc and the registry probe (php-src zend_print_long_to_buf) (#36386).
                if (IncDecResourceProvenance::cannotBeResourceForString($sourceOperand)) {
                    return new Variable(
                        $context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        self::fromLong($context, $value)
                    );
                }

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

    /**
     * Echo/cast fallback when compile-time class hints lie (e.g. ?ReflectionType locals
     * holding ReflectionNamedType — #25469).
     */
    public static function tryCoerceObjectVariableByStringableClassId(
        Context $context,
        Variable $objectVar,
    ): ?Variable {
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            return null;
        }
        self::ensureInsertBlock($context);
        $objPtr = $context->helper->loadValue($objectVar);
        $map = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $strPtr = self::coerceValueBoxObjectByStringableClassId(
            $context,
            $objectVar,
            $classIdVal,
            'obj_str_classid_'.spl_object_id($context)
        );

        return new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $strPtr
        );
    }

    /**
     * Value-boxed object cast to string without a compile-time class hint: dispatch __toString by
     * runtime class_id (DirectoryIterator foreach values, script globals — Zend cast handler).
     */
    private static function coerceValueBoxObjectByStringableClassId(
        Context $context,
        Variable $objVar,
        Value $classIdVal,
        string $tag
    ): Value {
        $objectBuiltin = $context->type->object;
        $stringable = [];
        foreach ($objectBuiltin->allClassNamesById() as $id => $name) {
            $lc = strtolower(ltrim((string) $name, '\\'));
            if ($objectBuiltin->classHasImplicitStringableLc($lc)) {
                $stringable[(int) $id] = ltrim((string) $name, '\\');
            }
        }
        $emptySample = $context->builder->load($context->constantStringFromString(''));
        if ([] === $stringable) {
            return $emptySample;
        }

        $i64 = $context->getTypeFromString('int64');
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $incoming = [];
        $ids = array_keys($stringable);
        $lastIdx = \count($ids) - 1;
        $fallbackBlock = BasicBlockHelper::append($context, $tag.'_fallback');

        foreach ($ids as $idx => $id) {
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $fallbackBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classIdVal,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $coerced = MagicMethodDispatch::coerceObjectToString($context, $objVar, $stringable[$id]);
            $str = null === $coerced
                ? $context->builder->load($context->constantStringFromString(''))
                : $context->helper->loadValue($coerced);
            $incoming[] = [$str, $context->builder->getInsertBlock()];
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($fallbackBlock);
        $throwable = self::tryCoerceThrowableToString($context, $objVar, '');
        $incoming[] = [$context->helper->loadValue($throwable), $context->builder->getInsertBlock()];
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($emptySample->typeOf(), $tag.'_phi');
        foreach ($incoming as [$val, $block]) {
            $phi->addIncoming($val, $block);
        }

        return $phi;
    }

    /**
     * Throwable/Exception (string) cast — catch(Throwable $e) and runtime class_id fallback (#26796).
     */
    private static function tryCoerceThrowableToString(
        Context $context,
        Variable $objVar,
        string $classHint
    ): Variable {
        $isThrowable = ReflectionBuiltinHelper::emitInstanceOf($context, $objVar, 'Throwable');
        $isBool = Variable::TYPE_NATIVE_BOOL === $isThrowable->type
            ? $isThrowable->value
            : $context->helper->loadValue($isThrowable);
        $tag = 'jit_cast_throwable_'.(++self::$coerceResumeSerial);
        $yesBb = BasicBlockHelper::append($context, $tag.'_yes');
        $noBb = BasicBlockHelper::append($context, $tag.'_no');
        $joinBb = BasicBlockHelper::append($context, $tag.'_join');
        $context->builder->branchIf($isBool, $yesBb, $noBb);
        $context->builder->positionAtEnd($yesBb);
        $toCall = $context->resolveFunctionProxy('exception::__tostring');
        $raw = $toCall->call($context, $objVar);
        $strPtr = (new \PHPCompiler\ext\standard\strval())->valueToString(
            $context,
            JitValueBox::coerceToValuePtrForStore($context, $raw)
        );
        $yesEnd = $context->builder->getInsertBlock();
        $context->builder->branch($joinBb);
        $context->builder->positionAtEnd($noBb);
        $hint = ltrim($classHint, '\\');
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
        $phi = $context->builder->phi($strPtr->typeOf(), $tag.'_phi');
        $phi->addIncoming($strPtr, $yesEnd);
        $phi->addIncoming($empty, $noEnd);

        return new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $phi
        );
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
        if ('%zu' === $format || '%lld' === $format || '%ld' === $format) {
            $i64 = $context->getTypeFromString('int64');
            $asI64 = $value->typeOf() === $i64
                ? $value
                : $context->builder->zExt($value, $i64);

            return self::fromLong($context, $asI64);
        }
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

    /**
     * zend_print_long_to_buf / _zend_i64_to_str — stack digits, one __string__alloc (#36386).
     *
     * php-src: Zend/zend_string.h zend_print_long_to_buf
     */
    public static function fromLong(Context $context, Value $longVal): Value
    {
        self::ensureI64Decimal($context);
        $i64 = $context->getTypeFromString('int64');
        $handle = $longVal->typeOf() === $i64
            ? $longVal
            : $context->builder->zExt($longVal, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__fromLong'),
            $handle
        );
    }

    /**
     * Write decimal digits of $longVal into a 24-byte entry alloca.
     *
     * @return array{0: Value, 1: Value} i8* pointer and i64 length
     */
    public static function writeDecimalDigits(Context $context, Value $longVal): array
    {
        self::ensureI64Decimal($context);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $handle = $longVal->typeOf() === $i64
            ? $longVal
            : $context->builder->zExt($longVal, $i64);
        $fn = BasicBlockHelper::parentFunction($context);
        $buf = BasicBlockHelper::entryAllocaForFunction(
            $context,
            $fn,
            $i8->arrayType(24)
        );
        $ptr = $context->builder->pointerCast($buf, $i8p);
        $len = $context->builder->call(
            $context->lookupFunction('__phpc_i64_decimal'),
            $handle,
            $ptr
        );

        return [$ptr, $len];
    }

    public static function ensureI64Decimal(Context $context): void
    {
        $dec = $context->module->getNamedFunction('__phpc_i64_decimal');
        $from = $context->module->getNamedFunction('__string__fromLong');
        $decReady = $dec instanceof LlvmFunction && $dec->countBasicBlocks() > 0;
        $fromReady = $from instanceof LlvmFunction && $from->countBasicBlocks() > 0;
        if ($decReady && $fromReady) {
            $context->registerFunction('__phpc_i64_decimal', $dec);
            $context->registerFunction('__string__fromLong', $from);

            return;
        }
        $restore = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            if (!$decReady) {
                self::implementI64Decimal($context);
            } else {
                $context->registerFunction('__phpc_i64_decimal', $dec);
            }
            if (!$fromReady) {
                self::implementFromLong($context);
            } else {
                $context->registerFunction('__string__fromLong', $from);
            }
        } finally {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    private static function implementI64Decimal(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $existing = $context->module->getNamedFunction('__phpc_i64_decimal');
        if ($existing instanceof LlvmFunction) {
            $fn = $existing;
        } else {
            $fnType = $context->context->functionType($i64, false, $i64, $i8p);
            $fn = $context->module->addFunction('__phpc_i64_decimal', $fnType);
        }
        $context->registerFunction('__phpc_i64_decimal', $fn);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $context->intrinsic->builder = $context->builder;
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('i64dec_entry');
        $zeroBb = $fn->appendBasicBlock('i64dec_zero');
        $checkMin = $fn->appendBasicBlock('i64dec_checkmin');
        $minBb = $fn->appendBasicBlock('i64dec_min');
        $negCheck = $fn->appendBasicBlock('i64dec_negcheck');
        $negBb = $fn->appendBasicBlock('i64dec_neg');
        $digitsSetup = $fn->appendBasicBlock('i64dec_digits_setup');
        $digitCond = $fn->appendBasicBlock('i64dec_digit_cond');
        $digitBody = $fn->appendBasicBlock('i64dec_digit_body');
        $signBb = $fn->appendBasicBlock('i64dec_sign');
        $writeMinus = $fn->appendBasicBlock('i64dec_write_minus');
        $emitBb = $fn->appendBasicBlock('i64dec_emit');

        $b->positionAtEnd($entry);
        $scratch = $b->alloca($i8->arrayType(24), 1, 'i64dec_scratch');
        $scratchBase = $b->pointerCast($scratch, $i8p);
        $posSlot = $b->alloca($i64, 1, 'i64dec_pos');
        $uSlot = $b->alloca($i64, 1, 'i64dec_u');
        $negSlot = $b->alloca($i64, 1, 'i64dec_neg');
        $b->store($i64->constInt(23, false), $posSlot);
        $b->store($i64->constInt(0, false), $negSlot);
        $val = $fn->getParam(0);
        $dest = $fn->getParam(1);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $val, $i64->constInt(0, false)),
            $zeroBb,
            $checkMin
        );

        $b->positionAtEnd($zeroBb);
        $b->store($i8->constInt(\ord('0'), false), $dest);
        $b->returnValue($i64->constInt(1, false));

        $b->positionAtEnd($checkMin);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $val, $i64->constInt(\PHP_INT_MIN, true)),
            $minBb,
            $negCheck
        );

        $b->positionAtEnd($minBb);
        $minLit = '-9223372036854775808';
        $minPtr = $b->pointerCast($context->constantFromString($minLit), $i8p);
        $context->intrinsic->memcpy(
            $dest,
            $minPtr,
            $i64->constInt(\strlen($minLit), false),
            false
        );
        $b->returnValue($i64->constInt(\strlen($minLit), false));

        $b->positionAtEnd($negCheck);
        $b->branchIf(
            $b->icmp(Builder::INT_SLT, $val, $i64->constInt(0, false)),
            $negBb,
            $digitsSetup
        );

        $b->positionAtEnd($negBb);
        $b->store($i64->constInt(1, false), $negSlot);
        $b->store($b->negate($val), $uSlot);
        $b->branch($digitCond);

        $b->positionAtEnd($digitsSetup);
        $b->store($val, $uSlot);
        $b->branch($digitCond);

        $b->positionAtEnd($digitCond);
        $u = $b->load($uSlot);
        $b->branchIf(
            $b->icmp(Builder::INT_NE, $u, $i64->constInt(0, false)),
            $digitBody,
            $signBb
        );

        $b->positionAtEnd($digitBody);
        $ten = $i64->constInt(10, false);
        $digit = $b->truncOrBitCast($b->signedRem($u, $ten), $i8);
        $ascii = $b->add($digit, $i8->constInt(\ord('0'), false));
        $pos = $b->load($posSlot);
        $b->store($ascii, $b->gep($scratchBase, $pos));
        $b->store($b->sub($pos, $i64->constInt(1, false)), $posSlot);
        $b->store($b->signedDiv($u, $ten), $uSlot);
        $b->branch($digitCond);

        $b->positionAtEnd($signBb);
        $b->branchIf(
            $b->icmp(Builder::INT_NE, $b->load($negSlot), $i64->constInt(0, false)),
            $writeMinus,
            $emitBb
        );

        $b->positionAtEnd($writeMinus);
        $pos = $b->load($posSlot);
        $b->store($i8->constInt(\ord('-'), false), $b->gep($scratchBase, $pos));
        $b->store($b->sub($pos, $i64->constInt(1, false)), $posSlot);
        $b->branch($emitBb);

        $b->positionAtEnd($emitBb);
        $pos = $b->load($posSlot);
        $start = $b->gep($scratchBase, $b->add($pos, $i64->constInt(1, false)));
        $len = $b->sub($i64->constInt(23, false), $pos);
        $context->intrinsic->memcpy($dest, $start, $len, false);
        $b->returnValue($len);
        $b->clearInsertionPosition();
    }

    private static function implementFromLong(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $existing = $context->module->getNamedFunction('__string__fromLong');
        if ($existing instanceof LlvmFunction) {
            $fn = $existing;
        } else {
            $fnType = $context->context->functionType($strPtr, false, $i64);
            $fn = $context->module->addFunction('__string__fromLong', $fnType);
        }
        $context->registerFunction('__string__fromLong', $fn);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('fromlong_entry');
        $context->builder->positionAtEnd($entry);
        $buf = $context->builder->alloca($i8->arrayType(24), 1, 'fromlong_buf');
        $ptr = $context->builder->pointerCast($buf, $i8p);
        $n = $context->builder->call(
            $context->lookupFunction('__phpc_i64_decimal'),
            $fn->getParam(0),
            $ptr
        );
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $n,
            $ptr
        );
        $context->builder->returnValue($str);
        $context->builder->clearInsertionPosition();
    }
}
