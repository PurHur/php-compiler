<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitPath;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GlobIteratorSnapshotRuntime;
use PHPCompiler\JIT\Builtin\StringPath;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT GlobIterator — glob snapshot `__spl_ht` + Iterator (#27422).
 *
 * Construct lists matches via {@see GlobIteratorSnapshotRuntime}.
 * current() returns $this (thin-AOT peer DirectoryIterator #27289); getFilename reads
 * basename synced into `__filename`. Keys are pathnames (php-src default KEY_AS_PATHNAME).
 *
 * php-src: ext/spl/spl_directory.c — GlobIterator
 */
final class GlobIteratorJitHelper
{
    public const PROP_HT = DirectoryIteratorJitHelper::PROP_HT;

    public const PROP_POS = DirectoryIteratorJitHelper::PROP_POS;

    public const PROP_FILENAME = DirectoryIteratorJitHelper::PROP_FILENAME;

    public const PROP_PATH = DirectoryIteratorJitHelper::PROP_PATH;

    public const PROP_FLAGS = DirectoryIteratorJitHelper::PROP_FLAGS;

    private const CLASS_NAME = 'GlobIterator';

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $pathArg,
        ?JITVariable $flagsArg
    ): Value {
        if (!$context->functionIsRegistered(GlobIteratorSnapshotRuntime::ABI)) {
            GlobIteratorSnapshotRuntime::ensureLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'gi_ctor_after_abi_link');
        }

        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $pathStr = self::loadString($context, $pathArg);
        $i64 = $context->getTypeFromString('int64');
        // php-src Z_PARAM_LONG $flags — soft-null DEP+0 outside strict_types (#31721).
        $flags = null !== $flagsArg
            ? JitStrictIntArg::lower($context, $flagsArg, 'GlobIterator::__construct', 2, 'flags')
            : $i64->constInt(0, false);

        $ht = $context->builder->call(
            $context->lookupFunction(GlobIteratorSnapshotRuntime::ABI),
            $pathStr,
            $flags
        );
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);

        $pathVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $pathStr);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_PATH),
            $pathVar,
            JITVariable::TYPE_STRING
        );
        self::storeLongPropertyValue($context, $obj, self::PROP_FLAGS, $flags);
        self::storeLongPropertyValue($context, $obj, self::PROP_POS, $i64->constInt(0, false));
        self::syncFromPos($context, $obj);
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, self::PROP_POS, $i64->constInt(0, false));
        self::syncFromPos($context, $obj);

        return self::voidResult($context);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj, self::PROP_POS);
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $ok = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return $slot;
    }

    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        // Thin AOT: return $this so getFilename resolves (peer #27289 FilesystemIterator).
        $obj = self::loadObject($context, $receiver);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }

    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        // Default KEY_AS_PATHNAME — php-src GlobIterator keys are path strings (#22306).
        $obj = self::loadObject($context, $receiver);
        $pathSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_PATH);
        $pathPtr = self::stringFromProperty($context, $pathSlot);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $pathPtr
        );

        return $slot;
    }

    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj, self::PROP_POS);
        $i64 = $context->getTypeFromString('int64');
        $next = $context->builder->addNoSignedWrap($pos, $i64->constInt(1, false));
        self::storeLongPropertyValue($context, $obj, self::PROP_POS, $next);
        self::syncFromPos($context, $obj);

        return self::voidResult($context);
    }

    public static function compileGetFilename(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $nameSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_FILENAME);
        $namePtr = self::stringFromProperty($context, $nameSlot);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $namePtr
        );

        return $slot;
    }

    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $n64);

        return $slot;
    }

    private static function syncFromPos(Context $context, Value $obj): void
    {
        StringPath::ensureLinked($context);
        $ht = self::htPtr($context, $obj);
        $pos = self::loadLongProperty($context, $obj, self::PROP_POS);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $inRange = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $okBb = BasicBlockHelper::append($context, 'gi_sync_ok');
        $emptyBb = BasicBlockHelper::append($context, 'gi_sync_empty');
        $done = BasicBlockHelper::append($context, 'gi_sync_done');
        $context->builder->branchIf($inRange, $okBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProperty($context, $obj, self::PROP_FILENAME, $empty);
        self::storeStringProperty($context, $obj, self::PROP_PATH, $empty);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($okBb);
        $idx = $context->builder->truncOrBitCast($pos, $sizeT);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $pathStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        self::storeStringProperty($context, $obj, self::PROP_PATH, $pathStr);
        $base = JitPath::basename($context, $pathStr);
        self::storeStringProperty($context, $obj, self::PROP_FILENAME, $base);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
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

        throw new \LogicException('GlobIterator method requires an object receiver');
    }

    private static function loadString(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            'GlobIterator path must be string, got '.JITVariable::getStringType($arg->type)
        );
    }

    private static function toI64(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->helper->loadValue($arg),
                $i64
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            return JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__value__toLong'),
                    JitValueBox::valuePtrFromVariable($context, $arg)
                ),
                $i64
            );
        }

        throw new \LogicException(
            'GlobIterator flags must be int, got '.JITVariable::getStringType($arg->type)
        );
    }

    private static function stringFromProperty(Context $context, JITVariable $slot): Value
    {
        if (JITVariable::TYPE_STRING === $slot->type) {
            return $context->helper->loadValue($slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );
    }

    private static function loadLongProperty(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, $prop);
        if (JITVariable::TYPE_NATIVE_LONG === $slot->type) {
            return $context->helper->loadValue($slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__toLong'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );
    }

    private static function storeLongPropertyValue(
        Context $context,
        Value $obj,
        string $prop,
        Value $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function storeStringProperty(
        Context $context,
        Value $obj,
        string $prop,
        Value $strPtr
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strPtr),
            JITVariable::TYPE_STRING
        );
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
