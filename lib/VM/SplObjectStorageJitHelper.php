<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplObjectStorage Iterator + getInfo/setInfo via `__spl_iter_pos` (#28707).
 *
 * Foreach walks `__objkey_node` and yields object keys (not info values);
 * method protocol shares order via an integer index into `objKeys`.
 *
 * php-src: ext/spl/spl_observer.c — spl_object_storage_* iterator / getInfo
 */
final class SplObjectStorageJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_ITER_POS = '__spl_iter_pos';

    public const CLASS_NAME = 'SplObjectStorage';

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        self::storeLongProperty($context, $obj, 0);

        return self::voidResult($context);
    }

    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $context->builder->add($pos, $i64->constInt(1, false)));

        return self::voidResult($context);
    }

    /**
     * Sync `__spl_iter_pos` from the foreach HT-walk index (#35030).
     *
     * Foreach walks `objKeys` with a private size_t index; getInfo/current/setInfo
     * read this property. Without this, every foreach body sees position 0.
     *
     * php-src: ext/spl/spl_observer.c — intern->index shared by iterator + getInfo
     */
    public static function syncIterPosFromForeachIndex(
        Context $context,
        JITVariable $receiver,
        Value $indexSizeT
    ): void {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $pos = $context->builder->zExtOrBitCast($indexSizeT, $i64);
        self::storeLongPropertyValue($context, $obj, $pos);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj);
        $sizeT = $context->getTypeFromString('size_t');
        $posSize = $context->builder->truncOrBitCast($pos, $sizeT);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $posSize, $n);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $inRange);

        return $slot;
    }

    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    /** current() — object key at iterator position (php-src spl_object_storage_get_current_data). */
    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        return self::compileAtPos($context, $receiver, false);
    }

    /** getInfo() — associated info at iterator position. */
    public static function compileGetInfo(Context $context, JITVariable $receiver): Value
    {
        return self::compileAtPos($context, $receiver, true);
    }

    public static function compileSetInfo(Context $context, JITVariable $receiver, JITVariable $info): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $pos = self::loadLongProperty($context, $obj);
        $node = self::nodeAtPos($context, $ht, $pos);
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $valid = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $okBb = BasicBlockHelper::append($context, 'sos_setinfo_ok');
        $doneBb = BasicBlockHelper::append($context, 'sos_setinfo_done');
        $context->builder->branchIf($valid, $okBb, $doneBb);

        $context->builder->positionAtEnd($okBb);
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        HashTableHelper::setAtObjectKey($context, $ht, $keyObj, $info);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return self::voidResult($context);
    }

    /**
     * php-src SplObjectStorage serialize — flat object/info pairs from objKeys (#33876).
     *
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33625.
     *
     * @return Value {@see __string__*} full `O:len:"SplObjectStorage":2:{…}` wire
     */
    public static function compileSerialize(Context $context, JITVariable $receiver): Value
    {
        \PHPCompiler\JIT\Builtin\StringSerialize::ensureLinked($context);
        $obj = self::loadObject($context, $receiver);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $srcHt = self::htPtr($context, $obj);
        $flat = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $idxSlot);

        self::foreachObjKeyNode($context, $srcHt, 'sos_ser', static function (
            Context $context,
            Value $node
        ) use ($flat, $idxSlot, $i64, $sizeT): void {
            $nodeMap = $context->structFieldMap['__objkey_node__'];
            $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
            $idx = $context->builder->load($idxSlot);
            $idxSize = $context->builder->truncOrBitCast($idx, $sizeT);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectAt'),
                $flat,
                $idxSize,
                $keyObj
            );
            $context->builder->store(
                $context->builder->add($idx, $i64->constInt(1, false)),
                $idxSlot
            );
            $idx2 = $context->builder->load($idxSlot);
            $valField = $context->builder->structGep($node, $nodeMap['value']);
            $idxBox = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $idxBox, $idx2);
            $idxVar = new JITVariable(
                $context,
                JITVariable::TYPE_VALUE,
                JITVariable::KIND_VARIABLE,
                $idxBox
            );
            $infoVar = new JITVariable(
                $context,
                JITVariable::TYPE_VALUE,
                JITVariable::KIND_VARIABLE,
                $valField
            );
            HashTableHelper::setValueBoxKey($context, $flat, $idxVar, $infoVar);
            $context->builder->store(
                $context->builder->add($idx2, $i64->constInt(1, false)),
                $idxSlot
            );
        });

        $logical = 'PHPCompiler\\ext\\standard\\SerializeSplObjectStorageNestedJitHelper::encodeWire';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/SerializeSplObjectStorageNestedJitHelper.php',
            [$logical],
            '#33876'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $logical, '#33876');
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );
        $args = [
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classNameStr,
                $fn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classLen,
                $fn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $flat,
                $fn->getParam(2)->typeOf()
            ),
        ];
        $raw = $context->builder->call($fn, ...$args);
        $strPtr = $context->getTypeFromString('__string__*');

        return \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr);
    }

    /**
     * php-src zim_SplObjectStorage_serialize — legacy `x:/m:` wire (#35117 / #31627).
     *
     * NestedJIT HT encode SIGABRTs on the method path (peer #34491); build wire in LLVM
     * with empty-stdClass keys + `__compiler_serialize_value` for info (matches #33876 limits).
     *
     * @return Value boxed `__value__*` string
     */
    public static function compileLegacySerialize(Context $context, JITVariable $receiver): Value
    {
        \PHPCompiler\JIT\Builtin\StringSerialize::ensureLinked($context);
        $obj = self::loadObject($context, $receiver);
        $srcHt = self::htPtr($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $countSlot);
        $bodySlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($empty, $bodySlot);

        self::foreachObjKeyNode($context, $srcHt, 'sos_leg_ser', static function (
            Context $context,
            Value $node
        ) use ($countSlot, $bodySlot, $i64): void {
            $nodeMap = $context->structFieldMap['__objkey_node__'];
            $valField = $context->builder->structGep($node, $nodeMap['value']);
            $infoVar = new JITVariable(
                $context,
                JITVariable::TYPE_VALUE,
                JITVariable::KIND_VARIABLE,
                $valField
            );
            $infoPtr = JitValueBox::valuePtrFromVariable($context, $infoVar);
            $infoWire = $context->builder->call(
                $context->lookupFunction('__compiler_serialize_value'),
                $infoPtr,
                $i64->constInt(0, false)
            );
            $objPart = $context->builder->load($context->constantStringFromString('O:8:"stdClass":0:{},'));
            $semi = $context->builder->load($context->constantStringFromString(';'));
            $piece = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $objPart, $infoWire);
            $piece = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $piece, $semi);
            $body = $context->builder->load($bodySlot);
            $context->builder->store(
                \PHPCompiler\ext\standard\JitStringConcat::concat($context, $body, $piece),
                $bodySlot
            );
            $context->builder->store(
                $context->builder->add($context->builder->load($countSlot), $i64->constInt(1, false)),
                $countSlot
            );
        });

        $count = $context->builder->load($countSlot);
        $countDigits = VmResourceIdString::formatNativeLong($context, $count);
        $xPrefix = $context->builder->load($context->constantStringFromString('x:i:'));
        $semi = $context->builder->load($context->constantStringFromString(';'));
        $mTail = $context->builder->load($context->constantStringFromString('m:a:0:{}'));
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $xPrefix, $countDigits);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $semi);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat(
            $context,
            $acc,
            $context->builder->load($bodySlot)
        );
        $wire = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $mTail);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $wire
        );

        return $slot;
    }

    /**
     * php-src zim_SplObjectStorage_unserialize — restore legacy `x:/m:` (#35117).
     */
    public static function compileLegacyUnserialize(
        Context $context,
        JITVariable $receiver,
        Value $payloadString
    ): Value {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_sos_attach_empty_stdclass_null(),
            new \PHPCompiler\ext\standard\phpc_native_sos_attach_empty_stdclass_long(),
            new \PHPCompiler\ext\standard\phpc_native_sos_attach_empty_stdclass_string(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $logical = 'PHPCompiler\\ext\\standard\\UnserializeSplObjectStorageLegacyNestedJitHelper::restoreInto';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplObjectStorageLegacyNestedJitHelper.php',
            [$logical],
            '#35117'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $logical, '#35117');
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);
        $destI64 = \PHPCompiler\JIT\JitNestedHelperCoerce::ptrToI64($context, $ht);
        $context->builder->call(
            $fn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fn->getParam(1)->typeOf()
            )
        );

        return self::voidResult($context);
    }

    /** Expose object load for {@see \PHPCompiler\ext\standard\JitSerialize} (#33876). */
    public static function loadObjectPtr(Context $context, JITVariable $receiver): Value
    {
        return self::loadObject($context, $receiver);
    }

    /**
     * php-src SplObjectStorage::__unserialize — restore pairs into `__spl_ht` (#33876).
     *
     * Do not write firstIntProp into slot 0 (that replaces the HT pointer — SIGSEGV on foreach).
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33636.
     */
    public static function compileUnserializeRestore(
        Context $context,
        Value $obj,
        Value $payloadString
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_sos_attach_empty_stdclass_null(),
            new \PHPCompiler\ext\standard\phpc_native_sos_attach_empty_stdclass_long(),
            new \PHPCompiler\ext\standard\phpc_native_sos_attach_empty_stdclass_string(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
        $ht = self::htPtr($context, $obj);
        $logical = 'PHPCompiler\\ext\\standard\\UnserializeSplObjectStorageNestedJitHelper::restoreInto';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplObjectStorageNestedJitHelper.php',
            [$logical],
            '#33876'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $logical, '#33876');
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);
        $destI64 = \PHPCompiler\JIT\JitNestedHelperCoerce::ptrToI64($context, $ht);
        $context->builder->call(
            $fn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fn->getParam(1)->typeOf()
            )
        );
    }

    /** Owned `__string__*` copy for NestedJIT PHP string params (#24137 / #33876). */
    private static function nestedJitOwnedString(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }

    /**
     * php-src zim_SplObjectStorage_addAll — merge every object+info from $other (#33847).
     */
    public static function compileAddAll(Context $context, JITVariable $receiver, JITVariable $other): Value
    {
        $destHt = self::htPtr($context, self::loadObject($context, $receiver));
        $otherObj = self::requireStorageArg($context, $other, 'addAll');
        if (null === $otherObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_addall_after_typeerror');

            return self::voidResult($context);
        }
        $srcHt = self::htPtr($context, $otherObj);
        self::foreachObjKeyNode($context, $srcHt, 'sos_addall', static function (
            Context $context,
            Value $node
        ) use ($destHt): void {
            $nodeMap = $context->structFieldMap['__objkey_node__'];
            $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
            $valField = $context->builder->structGep($node, $nodeMap['value']);
            $writable = HashTableHelper::writableObjectKeyValueBox($context, $destHt, $keyObj);
            JitValueBox::copyIntoPointer(
                $context,
                JitValueBox::valuePtrFromVariable($context, $writable),
                $valField
            );
        });

        return self::voidResult($context);
    }

    /**
     * php-src zim_SplObjectStorage_removeAll — detach every object present in $other (#33847).
     */
    public static function compileRemoveAll(Context $context, JITVariable $receiver, JITVariable $other): Value
    {
        $destHt = self::htPtr($context, self::loadObject($context, $receiver));
        $otherObj = self::requireStorageArg($context, $other, 'removeAll');
        if (null === $otherObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_removeall_after_typeerror');

            return self::voidResult($context);
        }
        $srcHt = self::htPtr($context, $otherObj);
        self::foreachObjKeyNode($context, $srcHt, 'sos_removeall', static function (
            Context $context,
            Value $node
        ) use ($destHt): void {
            $nodeMap = $context->structFieldMap['__objkey_node__'];
            $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
            HashTableHelper::unsetAtObjectKey($context, $destHt, $keyObj);
        });

        return self::voidResult($context);
    }

    /**
     * php-src zim_SplObjectStorage_removeAllExcept — keep only objects also in $other (#33847).
     */
    public static function compileRemoveAllExcept(Context $context, JITVariable $receiver, JITVariable $other): Value
    {
        $destHt = self::htPtr($context, self::loadObject($context, $receiver));
        $otherObj = self::requireStorageArg($context, $other, 'removeAllExcept');
        if (null === $otherObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_rae_after_typeerror');

            return self::voidResult($context);
        }
        $keepHt = self::htPtr($context, $otherObj);
        // Walk dest; save next before unset so the linked list stay walkable.
        self::foreachObjKeyNode($context, $destHt, 'sos_removeallexcept', static function (
            Context $context,
            Value $node
        ) use ($destHt, $keepHt): void {
            $nodeMap = $context->structFieldMap['__objkey_node__'];
            $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
            $isKept = $context->builder->call(
                $context->lookupFunction('__hashtable__objectKeyExists'),
                $keepHt,
                $keyObj
            );
            $dropBb = BasicBlockHelper::append($context, 'sos_rae_drop');
            $keepBb = BasicBlockHelper::append($context, 'sos_rae_keep');
            $context->builder->branchIf($isKept, $keepBb, $dropBb);
            $context->builder->positionAtEnd($dropBb);
            HashTableHelper::unsetAtObjectKey($context, $destHt, $keyObj);
            $context->builder->branch($keepBb);
            $context->builder->positionAtEnd($keepBb);
        });

        return self::voidResult($context);
    }

    /**
     * Z_PARAM_OBJ_OF_CLASS SplObjectStorage — TypeError cites Argument #1 ($storage) (#33847).
     *
     * @return Value|null  __object__* or null after TypeError (caller must not continue in this BB)
     */
    private static function requireStorageArg(Context $context, JITVariable $arg, string $method): ?Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'SplObjectStorage::%s(): Argument #1 ($storage) must be of type SplObjectStorage, null given',
                    $method
                )
            );

            return null;
        }
        if (JITVariable::TYPE_OBJECT !== $arg->type && JITVariable::TYPE_VALUE !== $arg->type) {
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'SplObjectStorage::%s(): Argument #1 ($storage) must be of type SplObjectStorage, %s given',
                    $method,
                    \PHPCompiler\JIT\JitOperandTypeLabel::givenLabel($context, $arg)
                )
            );

            return null;
        }

        $obj = self::loadObject($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));
        $expectedId = $context->type->object->lookup(self::CLASS_NAME);
        $i64 = $context->getTypeFromString('int64');
        $match = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($expectedId, false)
        );
        $okBb = BasicBlockHelper::append($context, 'sos_storage_arg_ok');
        $badBb = BasicBlockHelper::append($context, 'sos_storage_arg_bad');
        $context->builder->branchIf($match, $okBb, $badBb);
        $context->builder->positionAtEnd($badBb);
        // Exact class display name needs a runtime class-name table; "object" is honest for
        // thin AOT (VM path cites the real class). Functional merge/remove is the #33847 fix.
        \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            \sprintf(
                'SplObjectStorage::%s(): Argument #1 ($storage) must be of type SplObjectStorage, object given',
                $method
            )
        );
        $context->builder->positionAtEnd($okBb);

        return $obj;
    }

    /**
     * Walk `__hashtable__.objKeys` linked list; callback receives each `__objkey_node__*`.
     * Saves `next` before the callback so callers may unset the current key.
     *
     * @param callable(Context, Value): void $onNode
     */
    private static function foreachObjKeyNode(Context $context, Value $ht, string $prefix, callable $onNode): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $head = $context->builder->load($context->builder->structGep($ht, $map['objKeys']));

        $pre = BasicBlockHelper::append($context, $prefix.'_pre');
        $loop = BasicBlockHelper::append($context, $prefix.'_loop');
        $body = BasicBlockHelper::append($context, $prefix.'_body');
        $done = BasicBlockHelper::append($context, $prefix.'_done');
        $context->builder->branch($pre);

        $context->builder->positionAtEnd($pre);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $node = $context->builder->phi($nodePtrType);
        $node->addIncoming($head, $pre);
        $hasNode = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $context->builder->branchIf($hasNode, $body, $done);

        $context->builder->positionAtEnd($body);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $onNode($context, $node);
        // Callback may leave insert at a different block (e.g. removeAllExcept branch).
        $after = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($after);
        $context->builder->branch($loop);
        $node->addIncoming($nextNode, $after);

        $context->builder->positionAtEnd($done);
    }

    /**
     * @param bool $wantInfo true → node value (info); false → node key (object)
     */
    private static function compileAtPos(Context $context, JITVariable $receiver, bool $wantInfo): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $pos = self::loadLongProperty($context, $obj);
        $node = self::nodeAtPos($context, $ht, $pos);
        $out = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $out);
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $valid = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $okBb = BasicBlockHelper::append($context, $wantInfo ? 'sos_info_ok' : 'sos_cur_ok');
        $badBb = BasicBlockHelper::append($context, $wantInfo ? 'sos_info_bad' : 'sos_cur_bad');
        $doneBb = BasicBlockHelper::append($context, $wantInfo ? 'sos_info_done' : 'sos_cur_done');
        $context->builder->branchIf($valid, $okBb, $badBb);

        $context->builder->positionAtEnd($okBb);
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        if ($wantInfo) {
            $valField = $context->builder->structGep($node, $nodeMap['value']);
            JitValueBox::copyIntoPointer($context, $destPtr, $valField);
        } else {
            $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $destPtr,
                $keyObj
            );
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /** Walk `objKeys` linked list to the pos-th node (null if out of range). */
    private static function nodeAtPos(Context $context, Value $ht, Value $pos): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $head = $context->builder->load($context->builder->structGep($ht, $map['objKeys']));
        $posSize = $context->builder->truncOrBitCast($pos, $sizeT);
        $neg = $context->builder->icmp(Builder::INT_SLT, $pos, $i64->constInt(0, true));

        $pre = BasicBlockHelper::append($context, 'sos_walk_pre');
        $loop = BasicBlockHelper::append($context, 'sos_walk_loop');
        $body = BasicBlockHelper::append($context, 'sos_walk_body');
        $fail = BasicBlockHelper::append($context, 'sos_walk_fail');
        $ok = BasicBlockHelper::append($context, 'sos_walk_ok');
        $merge = BasicBlockHelper::append($context, 'sos_walk_merge');
        $context->builder->branchIf($neg, $fail, $pre);

        $context->builder->positionAtEnd($pre);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $node = $context->builder->phi($nodePtrType);
        $remain = $context->builder->phi($sizeT);
        $node->addIncoming($head, $pre);
        $remain->addIncoming($posSize, $pre);
        $hasNode = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $context->builder->branchIf($hasNode, $body, $fail);

        $context->builder->positionAtEnd($body);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remain, $zero);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $nextRemain = $context->builder->sub($remain, $one);
        $context->builder->branchIf($atTarget, $ok, $loop);
        $node->addIncoming($nextNode, $body);
        $remain->addIncoming($nextRemain, $body);

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($fail);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($nodePtrType);
        $result->addIncoming($node, $ok);
        $result->addIncoming($nodePtrType->constNull(), $fail);

        return $result;
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('SplObjectStorage method requires an object receiver');
    }

    private static function storeLongProperty(Context $context, Value $obj, int $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $i64->constInt($value, true));
    }

    private static function storeLongPropertyValue(Context $context, Value $obj, Value $value): void
    {
        $objectType = $context->type->object;
        $slot = $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_ITER_POS);
        $var = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value);
        $objectType->propertyStore($slot, $var, JITVariable::TYPE_NATIVE_LONG);
    }

    private static function loadLongProperty(Context $context, Value $obj): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_ITER_POS);

        return $context->helper->loadValue($slot);
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
