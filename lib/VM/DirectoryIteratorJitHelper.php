<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT DirectoryIterator / FilesystemIterator — snapshot `__spl_ht` + Iterator (#27289).
 *
 * Construct lists directory entries via {@see \PHPCompiler\ext\spl\DirectoryIteratorSnapshotJitHelper}
 * (NestedJIT leaf calling DirHandleJitHelper only — StringDir already linked).
 * current() returns `$this` (DirectoryIterator Zend semantics); isDot/getFilename read `__filename`.
 *
 * php-src: ext/spl/spl_directory.c
 */
final class DirectoryIteratorJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_POS = '__spl_iter_pos';

    public const PROP_FILENAME = '__filename';

    public const PROP_PATH = '__dir_path';

    public const PROP_FLAGS = '__flags';

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $pathArg,
        ?JITVariable $flagsArg,
        string $className
    ): Value {
        // ABI linked at Type init via DirectoryIteratorSnapshotRuntime — call only (#27289).
        if (!$context->functionIsRegistered(\PHPCompiler\JIT\Builtin\DirectoryIteratorSnapshotRuntime::ABI)) {
            \PHPCompiler\JIT\Builtin\DirectoryIteratorSnapshotRuntime::ensureLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'di_ctor_after_abi_link');
        }

        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $pathStr = self::loadString($context, $pathArg);
        $i64 = $context->getTypeFromString('int64');
        $flags = null !== $flagsArg
            ? self::toI64($context, $flagsArg)
            : $i64->constInt(0, false);

        $ht = $context->builder->call(
            $context->lookupFunction(\PHPCompiler\JIT\Builtin\DirectoryIteratorSnapshotRuntime::ABI),
            $pathStr,
            $flags
        );
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, $className, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);

        $pathVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $pathStr);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, self::PROP_PATH),
            $pathVar,
            JITVariable::TYPE_STRING
        );
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_FLAGS, $flags);
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $i64->constInt(0, false));
        self::syncFilenameFromPos($context, $obj, $className);
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    /** True when $namePtr is "." or "..". */
    private static function emitIsDotName(Context $context, Value $namePtr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $namePtr);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $bytes = $context->builder->pointerCast(
            $context->builder->structGep($namePtr, $strMap['value']),
            $i8p
        );
        $b0 = $context->builder->load($bytes);
        $b1 = $context->builder->load(
            $context->builder->gep($bytes, $i64->constInt(1, false))
        );
        $dot = $i8->constInt(ord('.'), false);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(1, false));
        $isTwo = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(2, false));
        $b0Dot = $context->builder->icmp(Builder::INT_EQ, $b0, $dot);
        $b1Dot = $context->builder->icmp(Builder::INT_EQ, $b1, $dot);
        $single = $context->builder->and($isOne, $b0Dot);
        $double = $context->builder->and($isTwo, $context->builder->and($b0Dot, $b1Dot));

        return $context->builder->or($single, $double);
    }

    public static function compileRewind(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $i64->constInt(0, false));
        self::syncFilenameFromPos($context, $obj, $className);

        return self::voidResult($context);
    }

    public static function compileValid(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj, $className);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $ok = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return $slot;
    }

    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        // DirectoryIterator::current() returns $this (php-src spl_directory.c).
        $obj = self::loadObject($context, $receiver);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }

    public static function compileKey(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    public static function compileNext(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $i64 = $context->getTypeFromString('int64');
        $next = $context->builder->addNoSignedWrap($pos, $i64->constInt(1, false));
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $next);
        self::syncFilenameFromPos($context, $obj, $className);

        return self::voidResult($context);
    }

    public static function compileIsDot(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $nameSlot = $context->type->object->propertyFetch($obj, $className, self::PROP_FILENAME);
        $namePtr = self::stringFromProperty($context, $nameSlot);
        $isDot = self::emitIsDotName($context, $namePtr);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $isDot);

        return $slot;
    }

    public static function compileGetFilename(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        // DirectoryIterator syncs basename into __filename; SplFileInfo stores it directly.
        $nameSlot = $context->type->object->propertyFetch($obj, $className, self::PROP_FILENAME);
        $namePtr = self::stringFromProperty($context, $nameSlot);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $namePtr
        );

        return $slot;
    }

    private static function syncFilenameFromPos(Context $context, Value $obj, string $className): void
    {
        $ht = self::htPtr($context, $obj, $className);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $inRange = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $okBb = BasicBlockHelper::append($context, 'di_sync_ok');
        $emptyBb = BasicBlockHelper::append($context, 'di_sync_empty');
        $done = BasicBlockHelper::append($context, 'di_sync_done');
        $context->builder->branchIf($inRange, $okBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProperty($context, $obj, $className, self::PROP_FILENAME, $empty);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($okBb);
        $idx = $context->builder->truncOrBitCast($pos, $sizeT);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        self::storeStringProperty($context, $obj, $className, self::PROP_FILENAME, $str);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function htPtr(Context $context, Value $obj, string $className): Value
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

        throw new \LogicException('DirectoryIterator method requires an object receiver');
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
            'DirectoryIterator path must be string, got '.JITVariable::getStringType($arg->type)
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
            'DirectoryIterator flags must be int, got '.JITVariable::getStringType($arg->type)
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

    private static function loadLongProperty(Context $context, Value $obj, string $class, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, $class, $prop);
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
        string $class,
        string $prop,
        Value $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $class, $prop),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function storeStringProperty(
        Context $context,
        Value $obj,
        string $class,
        string $prop,
        Value $strPtr
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $class, $prop),
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
