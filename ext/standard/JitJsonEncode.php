<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\JsonEncodeQuoteStringRuntime;
use PHPCompiler\JIT\Builtin\StringJsonEncode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT json_encode() lowering via JsonEncodeJitHelper PHP (#6852, #9267, #27020).
 *
 * Boxed `__value__*` arrays (get_object_vars AOT) must use the hashtable ABI —
 * NestedJIT encodeValue resolveIndirect on those boxes SIGSEGVs (#27020).
 * Objects: NestedJIT encodeValue quotes class names — route via get_object_vars (#28638).
 * DateTime/DateTimeImmutable/DateTimeZone: Zend wire, not empty get_object_vars (#33752 / #14143).
 * Enum cases: VmJson export wire (JsonSerializable + backing scalar), not get_object_vars (#6880).
 */
final class JitJsonEncode
{
    private static int $blockSerial = 0;

    public static function encode(Context $context, JITVariable $arg, Value $flags): Value
    {
        StringJsonEncode::ensureLinked($context);

        $enumFold = self::tryFoldEnumCase($context, $arg, 0);
        if (null !== $enumFold) {
            return $enumFold;
        }

        if (JITVariable::TYPE_HASHTABLE === $arg->type || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            $ht = JITVariable::TYPE_HASHTABLE === $arg->type
                ? $context->helper->loadValue($arg)
                : ArrayBuiltinHelper::loadHashTable($context, $arg);
            // Rematerialize only when NestedJIT export would see an empty Cow/view HT.
            // Unconditional alloc+overlay was emptying ordinary packed HTs under #31101
            // BB ownership (json_encode([1,2]) → "{}"). Prefer direct encode; overlay
            // remains available via replaceRecursiveCopy for true Cow cases (#26977).
            return self::stringOrFalse(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__compiler_json_encode_array'),
                    $ht,
                    $flags,
                    $flags
                )
            );
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $dateFold = self::tryFoldDateTimeFamily($context, $arg, 0);
            if (null !== $dateFold) {
                return $dateFold;
            }

            return self::stringOrFalse(
                $context,
                self::encodeObjectPublicProps($context, $arg, $flags)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::stringOrFalse(
                $context,
                self::encodeBoxedValue($context, JitValueBox::valuePtrFromVariable($context, $arg), $flags)
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            $strPtr = JITVariable::KIND_VALUE === $arg->kind && null !== $arg->value
                ? $arg->value
                : JitStringArg::lowerDominating($context, $arg, 'json_encode string');
            $strTy = $context->getStringFromType($strPtr->typeOf());
            if (JitStringArg::isStringPtrPtrType($strTy)) {
                $strPtr = $context->builder->load($strPtr);
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $strPtr
            );

            return self::stringOrFalse(
                $context,
                JsonEncodeQuoteStringRuntime::quote($context, $owned)
            );
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::assignToPointer($context, $ptr, $arg);

        return self::stringOrFalse(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_value'),
                $ptr,
                $flags
            )
        );
    }

    /**
     * Fold DateTime / DateTimeImmutable / DateTimeZone to Zend JSON wire (#33752, re-#14143).
     *
     * Thin AOT `get_object_vars` strips `__dt_*` storage (#22445) → `{}`. Use the same
     * compile-time stamps {@see JitDateTimeConstruct} / {@see JitDateTimeZoneConstruct}
     * already leave on the receiver (peer SplFixedArray `#33723`).
     *
     * php-src: ext/json/php_json.c + ext/date/php_date.c date object handlers
     */
    public static function tryFoldDateTimeFamily(Context $context, JITVariable $arg, int $flags): ?Value
    {
        $wire = self::compileTimeDateTimeFamilyWire($arg);
        if (null === $wire) {
            return null;
        }

        try {
            $encoded = VmJsonFormat::encodeExported($wire, $flags);
        } catch (VmJsonExportException $e) {
            if (VmJsonFlags::throwsOnError($flags)) {
                return JitJsonThrow::emitFromException(
                    $context,
                    new \JsonException(VmJson::errorMsgForCode($e->errorCode), $e->errorCode)
                );
            }
            JitJsonEncodeCompileTime::emitSetLastError($context, $e->errorCode);
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return JitValueBox::pointer($context, $slot);
        } catch (\JsonException $e) {
            return JitJsonThrow::emitFromException($context, $e);
        }
        if (false === $encoded) {
            $sticky = VmJson::lastError();
            JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return JitValueBox::pointer($context, $slot);
        }
        $sticky = VmJson::lastError();
        if (0 !== $sticky) {
            JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * @return array{date: string, timezone_type: int, timezone: string}|array{timezone_type: int, timezone: string}|null
     */
    private static function compileTimeDateTimeFamilyWire(JITVariable $arg): ?array
    {
        if (null !== $arg->compileTimeDateTimeTimestamp) {
            $tz = $arg->compileTimeTimezoneName ?? 'UTC';
            $timestamp = (int) $arg->compileTimeDateTimeTimestamp;
            // Construct path stores microsecond 0 for literal fixtures; format matches Zend (#14143).
            $micro = 0;

            return [
                'date' => VmDateTimeNative::formatZendDateWire($timestamp, $micro, $tz),
                'timezone_type' => DateTimeSupport::zendTimezoneWireType($tz),
                'timezone' => $tz,
            ];
        }
        // DateTimeZone::__construct stamps zone id without a DateTime timestamp (#29732 / #33752).
        if (null !== $arg->compileTimeTimezoneName && '' !== $arg->compileTimeTimezoneName) {
            $tz = $arg->compileTimeTimezoneName;

            return [
                'timezone_type' => DateTimeSupport::zendTimezoneWireType($tz),
                'timezone' => $tz,
            ];
        }

        return null;
    }

    /**
     * Fold enum case singletons to Zend json_encode wire (#6880, re-AOT regression).
     *
     * Thin AOT routes TYPE_OBJECT enum receivers through get_object_vars → {"name","value"}
     * instead of backing scalars or JsonSerializable::jsonSerialize(). VmJson::export is
     * the VM truth — reuse it at compile time when compileTimeEnumCase metadata is present.
     *
     * php-src: ext/json/php_json.c — php_json_encode_enum before default object encoding
     */
    public static function tryFoldEnumCase(Context $context, JITVariable $arg, int $flags): ?Value
    {
        $wire = self::compileTimeEnumCaseWire($context, $arg);
        if (null === $wire) {
            return null;
        }

        try {
            $encoded = VmJsonFormat::encodeExported($wire, $flags);
        } catch (VmJsonExportException $e) {
            if (VmJsonFlags::throwsOnError($flags)) {
                return JitJsonThrow::emitFromException(
                    $context,
                    new \JsonException(VmJson::errorMsgForCode($e->errorCode), $e->errorCode)
                );
            }
            JitJsonEncodeCompileTime::emitSetLastError($context, $e->errorCode);
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return JitValueBox::pointer($context, $slot);
        } catch (\JsonException $e) {
            return JitJsonThrow::emitFromException($context, $e);
        }
        if (false === $encoded) {
            $sticky = VmJson::lastError();
            JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return JitValueBox::pointer($context, $slot);
        }
        $sticky = VmJson::lastError();
        if (0 !== $sticky) {
            JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|null
     */
    private static function compileTimeEnumCaseWire(Context $context, JITVariable $arg): mixed
    {
        if (null === $arg->compileTimeEnumCase) {
            return null;
        }
        $objectBuiltin = $context->type->object;
        if (!$objectBuiltin instanceof ObjectBuiltin) {
            return null;
        }
        $classId = (int) $arg->compileTimeEnumCase['classId'];
        $caseKey = (string) $arg->compileTimeEnumCase['caseKey'];
        if (!$objectBuiltin->isEnumClassId($classId)) {
            return null;
        }
        try {
            $className = $objectBuiltin->classNameForId($classId);
        } catch (\LogicException) {
            return null;
        }
        $classLc = strtolower(ltrim($className, '\\'));
        $ifaces = $objectBuiltin->allInterfacesForClassLc($classLc);
        $hasJsonSerializable = \in_array('jsonserializable', $ifaces, true);

        if (!$hasJsonSerializable) {
            $backedType = $objectBuiltin->enumBackedTypeFor($classId);
            if (null === $backedType) {
                throw new VmJsonExportException(VmJson::ERROR_NON_BACKED_ENUM);
            }

            return $objectBuiltin->enumCaseBackingScalarForCase($classId, $caseKey);
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null === $vmCtx) {
            return null;
        }
        $vm = $context->runtime->vm();
        $entry = $vmCtx->classes[$classLc] ?? null;
        if (null === $entry) {
            $entry = new ClassEntry($className);
            $vmCtx->classes[$classLc] = $entry;
        }
        $entry->isEnum = true;
        $entry->backedType = $objectBuiltin->enumBackedTypeFor($classId);
        $entry->interfaces = array_values(array_filter(
            $ifaces,
            static fn (string $iface): bool => $objectBuiltin->isInterfaceClassLc($iface)
        ));
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $caseVar = new VmVariable();
        if (!EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($entry, $caseKey, $caseVar)) {
            $caseName = $objectBuiltin->enumCaseCanonicalName($classId, $caseKey);
            $backing = new VmVariable(VmVariable::TYPE_NULL);
            $backing->null();
            $backedType = $entry->backedType;
            if (null !== $backedType) {
                $scalar = $objectBuiltin->enumCaseBackingScalarForCase($classId, $caseKey);
                match ($backedType) {
                    'int' => $backing->int((int) $scalar),
                    'string' => $backing->string((string) $scalar),
                    default => null,
                };
            }
            $caseVar = EnumCaseSupport::compileTimeCaseVariable($entry, $caseName, $backing);
        }
        try {
            return VmJson::export($caseVar->resolveIndirect(), $vmCtx, $vm, null, 512);
        } catch (VmJsonExportException $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Public props via get_object_vars + FORCE_OBJECT so empty objects encode as {} (#28638).
     * ArrayObject/ArrayIterator store in `__spl_ht` — get_object_vars is empty under thin AOT (#33619).
     * SplFixedArray::jsonSerialize → toArray(); encode `__spl_ht` without FORCE_OBJECT (#33723).
     * DateTime* Zend wire folded in {@see tryFoldDateTimeFamily} (#33752).
     * php-src: ext/json/json_encoder.c — php_json_encode_object / zend_get_properties_for
     * php-src: ext/spl/spl_array.c — spl_array_get_properties returns the array HT
     * php-src: ext/spl/spl_fixedarray.c — zim_SplFixedArray_jsonSerialize
     */
    private static function encodeObjectPublicProps(Context $context, JITVariable $arg, Value $flags): Value
    {
        $dateFold = self::tryFoldDateTimeFamily($context, $arg, 0);
        if (null !== $dateFold) {
            // Already a string* / false box — callers of encode() wrap TYPE_OBJECT; this
            // path is also used from encodeBoxedValue which expects a raw string*.
            // Constant DateTime wire is always a string pointer.
            return $dateFold;
        }

        $force = $context->getTypeFromString('int64')->constInt(VmJsonFlags::FORCE_OBJECT, false);
        $flagsObj = $context->builder->or($flags, $force);
        $splEncoded = self::tryEncodeSplArrayObjectStorage($context, $arg, $flags, $flagsObj);
        if (null !== $splEncoded) {
            return $splEncoded;
        }

        $boxed = JitGetObjectVars::invoke($context, $arg, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $boxed
        );
        // Skip unconditional overlay — see encode() (#31101). FORCE_OBJECT still applied.

        return $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $ht,
            $flagsObj,
            $flags
        );
    }

    /**
     * Encode SPL storage via `__spl_ht` (#33619 / #33723).
     *
     * ArrayObject family: FORCE_OBJECT (php-src spl_array_get_properties → object wire).
     * SplFixedArray: original flags only (JsonSerializable toArray → JSON array, not {}).
     * Null pads leave numElements < nextFreeElement (#27285); sync before encode so
     * isPackedList is true (jsonSerialize includes null holes as list elements).
     *
     * Returns null when the operand is not a resolved object pointer we can class-id dispatch.
     */
    private static function tryEncodeSplArrayObjectStorage(
        Context $context,
        JITVariable $arg,
        Value $flags,
        Value $flagsObj
    ): ?Value {
        $objVar = self::resolveObjectReceiver($context, $arg);
        if (null === $objVar) {
            return null;
        }

        $objectType = $context->type->object;
        $aoId = $objectType->lookup('ArrayObject');
        $aiId = $objectType->lookup('ArrayIterator');
        $raiId = $objectType->lookup('RecursiveArrayIterator');
        $fixedId = $objectType->lookup('SplFixedArray');
        $objPtr = $context->helper->loadValue($objVar);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $isAo = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($aoId, false)
        );
        $isAi = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($aiId, false)
        );
        $isRai = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($raiId, false)
        );
        $isFixed = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($fixedId, false)
        );
        $isSplArray = $context->builder->or($context->builder->or($isAo, $isAi), $isRai);
        $isSpl = $context->builder->or($isSplArray, $isFixed);

        $id = (string) (++self::$blockSerial);
        $splBlock = BasicBlockHelper::append($context, 'json_encode_spl_array_'.$id);
        $plainBlock = BasicBlockHelper::append($context, 'json_encode_spl_plain_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'json_encode_spl_done_'.$id);
        $context->builder->branchIf($isSpl, $splBlock, $plainBlock);

        $context->builder->positionAtEnd($splBlock);
        $fixedBlock = BasicBlockHelper::append($context, 'json_encode_spl_fixed_'.$id);
        $aoBlock = BasicBlockHelper::append($context, 'json_encode_spl_ao_'.$id);
        $context->builder->branchIf($isFixed, $fixedBlock, $aoBlock);

        $context->builder->positionAtEnd($fixedBlock);
        $htVarFixed = $objectType->splBackingHashtable($objVar);
        $htFixed = $context->helper->loadValue($htVarFixed);
        // toArray/jsonSerialize: every slot is an element (null pads included) (#33723 / #27285).
        $htMap = $context->structFieldMap['__hashtable__'];
        $slotCount = $context->builder->load(
            $context->builder->structGep($htFixed, $htMap['nextFreeElement'])
        );
        $context->builder->store(
            $slotCount,
            $context->builder->structGep($htFixed, $htMap['numElements'])
        );
        $fixedResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $htFixed,
            $flags,
            $flags
        );
        $fixedEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($aoBlock);
        $htVar = $objectType->splBackingHashtable($objVar);
        $splResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $context->helper->loadValue($htVar),
            $flagsObj,
            $flags
        );
        $splEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($plainBlock);
        $boxed = JitGetObjectVars::invoke($context, $arg, false);
        $plainHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $boxed
        );
        $plainResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $plainHt,
            $flagsObj,
            $flags
        );
        $plainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'json_encode_spl_phi_'.$id);
        $phi->addIncoming($fixedResult, $fixedEnd);
        $phi->addIncoming($splResult, $splEnd);
        $phi->addIncoming($plainResult, $plainEnd);

        return $phi;
    }

    /** @return JITVariable|null TYPE_OBJECT receiver for class_id / `__spl_ht` */
    private static function resolveObjectReceiver(Context $context, JITVariable $arg): ?JITVariable
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            return null;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $valuePtr)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $objPtr
        );
    }

    /**
     * Route boxed hashtables / objects — NestedJIT encodeValue quotes class names (#27020 / #28638).
     * Also used from {@see JsonEncodeArrayLlvm} pair values (#26367).
     */
    public static function encodeBoxedValue(Context $context, Value $valuePtr, Value $flags): Value
    {
        $valuePtr = JitValueBox::normalizeValuePtr($context, $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isJitHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $isHt = $context->builder->or($isJitHt, $isVmArray);
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
        );
        // Tag collisions (#26367 / #32326 / #33520):
        //   JIT TYPE_NATIVE_BOOL (=2) ↔ VM TYPE_FLOAT (=2)
        //   JIT TYPE_NATIVE_DOUBLE (=3) ↔ VM TYPE_BOOLEAN (=3)
        // Prefer JIT bool (2) before any float/double path. Keep native-double (3) before VM bool
        // so real doubles are not read as bool bytes; HashTable export must not remap bool→3.
        $isJitBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $isVmBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_BOOLEAN, false)
        );

        $id = (string) (++self::$blockSerial);
        $htBlock = BasicBlockHelper::append($context, 'json_encode_boxed_ht_'.$id);
        $objCheck = BasicBlockHelper::append($context, 'json_encode_boxed_objchk_'.$id);
        $objBlock = BasicBlockHelper::append($context, 'json_encode_boxed_obj_'.$id);
        $scalarCheck = BasicBlockHelper::append($context, 'json_encode_boxed_scalar_'.$id);
        $jitBoolCheck = BasicBlockHelper::append($context, 'json_encode_boxed_jit_bool_'.$id);
        $nativeDoubleCheck = BasicBlockHelper::append($context, 'json_encode_boxed_native_double_'.$id);
        $vmBoolCheck = BasicBlockHelper::append($context, 'json_encode_boxed_vm_bool_'.$id);
        $boolCheck = BasicBlockHelper::append($context, 'json_encode_boxed_bool_'.$id);
        $boolTrueBlock = BasicBlockHelper::append($context, 'json_encode_boxed_bool_true_'.$id);
        $boolFalseBlock = BasicBlockHelper::append($context, 'json_encode_boxed_bool_false_'.$id);
        $valueBlock = BasicBlockHelper::append($context, 'json_encode_boxed_value_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'json_encode_boxed_done_'.$id);
        $context->builder->branchIf($isHt, $htBlock, $objCheck);

        $context->builder->positionAtEnd($htBlock);
        $boxedArray = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            $valuePtr
        );
        $ht = HashTableReadLlvm::loadHashtablePointer($context, $boxedArray);
        // Skip unconditional overlay — see encode() (#31101).
        $htResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $ht,
            $flags,
            $flags
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objCheck);
        $context->builder->branchIf($isObj, $objBlock, $scalarCheck);

        $context->builder->positionAtEnd($scalarCheck);
        $context->builder->branchIf($isJitBool, $boolCheck, $jitBoolCheck);

        $context->builder->positionAtEnd($jitBoolCheck);
        $context->builder->branchIf($isNativeDouble, $valueBlock, $nativeDoubleCheck);

        $context->builder->positionAtEnd($nativeDoubleCheck);
        $context->builder->branchIf($isVmBool, $boolCheck, $vmBoolCheck);

        $context->builder->positionAtEnd($vmBoolCheck);
        $context->builder->branch($valueBlock);

        $context->builder->positionAtEnd($boolCheck);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($isTrue, $boolTrueBlock, $boolFalseBlock);

        $context->builder->positionAtEnd($boolTrueBlock);
        $boolTrueResult = $context->builder->load($context->constantStringFromString('true'));
        $boolTrueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($boolFalseBlock);
        $boolFalseResult = $context->builder->load($context->constantStringFromString('false'));
        $boolFalseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        $objVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $valuePtr);
        $objResult = self::encodeObjectPublicProps($context, $objVar, $flags);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($valueBlock);
        $valueResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_value'),
            $valuePtr,
            $flags
        );
        $valueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'json_encode_boxed_phi_'.$id);
        $phi->addIncoming($htResult, $htEnd);
        $phi->addIncoming($objResult, $objEnd);
        $phi->addIncoming($boolTrueResult, $boolTrueEnd);
        $phi->addIncoming($boolFalseResult, $boolFalseEnd);
        $phi->addIncoming($valueResult, $valueEnd);

        return $phi;
    }

    /** @return Value __value__* — false bool when {@param $result} is null (Zend json_encode failure). */
    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'json_encode_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'json_encode_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'json_encode_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->call($context->lookupFunction('__string__separate'), $result)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
