<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitStat;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatPathRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT DirectoryIterator / FilesystemIterator — snapshot `__spl_ht` + Iterator (#27289, #33263).
 *
 * Construct lists directory entries via {@see \PHPCompiler\ext\spl\DirectoryIteratorSnapshotJitHelper}
 * (NestedJIT leaf calling DirHandleJitHelper only — StringDir already linked).
 * current() returns `$this` (DirectoryIterator Zend semantics); isDot/getFilename read `__filename`.
 * isFile/isDir join `__dir_path`+`__filename` then {@see \PHPCompiler\ext\standard\JitStat}.
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
        // php-src Z_PARAM_LONG $flags — soft-null DEP+0 outside strict_types (#31721).
        $flags = null !== $flagsArg
            ? JitStrictIntArg::lower($context, $flagsArg, $className.'::__construct', 2, 'flags')
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

    /**
     * SplFileInfo::getPathname / __toString — join(__dir_path, __filename) (#33274).
     * php-src: zim_SplFileInfo_getPathname / __toString
     */
    public static function compileGetPathname(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pathname = self::emitJoinedPathname($context, $obj, $className);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $pathname
        );

        return $slot;
    }

    /**
     * SplFileInfo::getPath — `__dir_path` only (#33274).
     * php-src: zim_SplFileInfo_getPath (intern->path)
     */
    public static function compileGetPath(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pathSlot = $context->type->object->propertyFetch($obj, $className, self::PROP_PATH);
        $pathPtr = self::stringFromProperty($context, $pathSlot);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $pathPtr
        );

        return $slot;
    }

    /**
     * SplFileInfo::isFile — pathname = join(__dir_path, __filename) (#33263).
     * php-src: ext/spl/spl_directory.c — zim_SplFileInfo_isFile
     */
    public static function compileIsFile(Context $context, JITVariable $receiver, string $className): Value
    {
        return self::compilePathPredicate($context, $receiver, $className, 'file');
    }

    /**
     * SplFileInfo::isDir — pathname = join(__dir_path, __filename) (#33263).
     * php-src: ext/spl/spl_directory.c — zim_SplFileInfo_isDir
     */
    public static function compileIsDir(Context $context, JITVariable $receiver, string $className): Value
    {
        return self::compilePathPredicate($context, $receiver, $className, 'dir');
    }

    /**
     * SplFileInfo::isLink — pathname = join(__dir_path, __filename) (#33269).
     * php-src: ext/spl/spl_directory.c — zim_SplFileInfo_isLink
     */
    public static function compileIsLink(Context $context, JITVariable $receiver, string $className): Value
    {
        return self::compilePathPredicate($context, $receiver, $className, 'link');
    }

    /**
     * SplFileInfo::isReadable (#33269). php-src: zim_SplFileInfo_isReadable
     */
    public static function compileIsReadable(Context $context, JITVariable $receiver, string $className): Value
    {
        return self::compilePathPredicate($context, $receiver, $className, 'readable');
    }

    /**
     * SplFileInfo::isWritable (#33269). php-src: zim_SplFileInfo_isWritable
     */
    public static function compileIsWritable(Context $context, JITVariable $receiver, string $className): Value
    {
        return self::compilePathPredicate($context, $receiver, $className, 'writable');
    }

    /**
     * SplFileInfo::isExecutable (#33269). php-src: zim_SplFileInfo_isExecutable
     */
    public static function compileIsExecutable(Context $context, JITVariable $receiver, string $className): Value
    {
        return self::compilePathPredicate($context, $receiver, $className, 'executable');
    }

    /** @param 'file'|'dir'|'link'|'readable'|'writable'|'executable' $kind */
    private static function compilePathPredicate(
        Context $context,
        JITVariable $receiver,
        string $className,
        string $kind
    ): Value {
        StatPathRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'di_path_pred_after_stat_link');

        $obj = self::loadObject($context, $receiver);
        $pathname = self::emitJoinedPathname($context, $obj, $className);
        $pred = match ($kind) {
            'file' => JitStat::pathIsFile($context, $pathname),
            'dir' => JitStat::pathIsDir($context, $pathname),
            'link' => JitStat::pathIsLink($context, $pathname),
            'readable' => JitStat::pathIsReadable($context, $pathname),
            'writable' => JitStat::pathIsWritable($context, $pathname),
            'executable' => JitStat::pathIsExecutable($context, $pathname),
            default => throw new \LogicException('Unknown SplFileInfo path predicate: '.$kind),
        };
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $pred);

        return $slot;
    }

    /**
     * Mirror {@see \PHPCompiler\ext\spl\DirectoryIteratorBuiltin::joinPath} / pathname().
     */
    private static function emitJoinedPathname(Context $context, Value $obj, string $className): Value
    {
        $dirSlot = $context->type->object->propertyFetch($obj, $className, self::PROP_PATH);
        $nameSlot = $context->type->object->propertyFetch($obj, $className, self::PROP_FILENAME);
        $dirPtr = self::stringFromProperty($context, $dirSlot);
        $namePtr = self::stringFromProperty($context, $nameSlot);

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        // Alloca — JitStringConcat ends in its own blocks; PHI preds would not match (#33263).
        $outSlot = $context->builder->alloca($strPtrTy);

        $dirLen = $context->builder->call($context->lookupFunction('__string__strlen'), $dirPtr);
        $dirEmpty = $context->builder->icmp(Builder::INT_EQ, $dirLen, $zero);

        $isDotDir = BasicBlockHelper::append($context, 'di_join_dotdir');
        $checkSlash = BasicBlockHelper::append($context, 'di_join_slash');
        $useName = BasicBlockHelper::append($context, 'di_join_name');
        $joinSlash = BasicBlockHelper::append($context, 'di_join_addslash');
        $joinBare = BasicBlockHelper::append($context, 'di_join_bare');
        $done = BasicBlockHelper::append($context, 'di_join_done');
        $context->builder->branchIf($dirEmpty, $useName, $isDotDir);

        $context->builder->positionAtEnd($isDotDir);
        $dirBytes = $context->builder->pointerCast(
            $context->builder->structGep($dirPtr, $strMap['value']),
            $i8p
        );
        $b0 = $context->builder->load($dirBytes);
        $lenIsOne = $context->builder->icmp(Builder::INT_EQ, $dirLen, $one);
        $isDot = $context->builder->icmp(Builder::INT_EQ, $b0, $i8->constInt(ord('.'), false));
        $dirIsDot = $context->builder->and($lenIsOne, $isDot);
        $context->builder->branchIf($dirIsDot, $useName, $checkSlash);

        $context->builder->positionAtEnd($checkSlash);
        $lastIdx = $context->builder->sub($dirLen, $one);
        $lastByte = $context->builder->load($context->builder->gep($dirBytes, $lastIdx));
        $endsSlash = $context->builder->icmp(Builder::INT_EQ, $lastByte, $i8->constInt(ord('/'), false));
        $context->builder->branchIf($endsSlash, $joinBare, $joinSlash);

        $context->builder->positionAtEnd($useName);
        $context->builder->store($namePtr, $outSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($joinBare);
        $bare = JitStringConcat::concat($context, $dirPtr, $namePtr);
        $context->builder->store($bare, $outSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($joinSlash);
        $slash = $context->builder->load($context->constantStringFromString('/'));
        $withSlash = JitStringConcat::concat($context, $dirPtr, $slash);
        $joined = JitStringConcat::concat($context, $withSlash, $namePtr);
        $context->builder->store($joined, $outSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($outSlot);
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
