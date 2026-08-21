<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitPath;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SplFileObjectSnapshotRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplFileObject — snapshot lines into `__spl_ht` for foreach (#28709, #33305, #33308).
 *
 * Construct / openFile read via libc file_get_contents then NestedJIT explode line split
 * (#33308 — concat-loop NestedJIT SIGSEGV'd in __ref__delref).
 * Path accessors read `__pathname` (#33305); also init SplFileInfo `__dir_path`/`__filename`
 * for inherited isFile/getSize/… (#33313).
 * Iterator I/O (`fgets`/`current`/…) walks the snapshot via cursor + lineNum (#33319).
 * Foreach walks packed `__spl_ht` ({@see SplOuterIteratorHt}).
 *
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / zim_SplFileInfo_openFile
 */
final class SplFileObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_PATH = '__pathname';

    /** Next unread snapshot index (stream cursor). */
    public const PROP_CURSOR = '__spl_iter_pos';

    /** SplFileObject::key() — php-src current_line_num. */
    public const PROP_LINE = '__spl_line_num';

    /** Index of loaded current line, or -1 when none. */
    public const PROP_CUR = '__spl_cur_idx';

    private const CLASS_NAME = 'SplFileObject';

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $pathArg
    ): Value {
        $obj = self::loadObject($context, $receiver);
        self::initConstructedFromPath($context, $obj, self::loadString($context, $pathArg));

        return self::voidResult($context);
    }

    /**
     * Allocate SplFileObject and init from pathname (openFile / factories) (#33305).
     */
    public static function emitNewFromPathname(Context $context, Value $pathStr): Value
    {
        $classId = $context->type->object->lookup(self::CLASS_NAME);
        $newObj = $context->type->object->allocate($classId);
        self::initConstructedFromPath($context, $newObj, $pathStr);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $newObj
        );

        return $slot;
    }

    /** SplFileObject::getFilename — basename(__pathname) (#33305). */
    public static function compileGetFilename(Context $context, JITVariable $receiver): Value
    {
        $pathname = self::loadPathname($context, $receiver);
        $name = JitPath::basename($context, $pathname);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $name
        );

        return $slot;
    }

    /** SplFileObject::getPathname / __toString (#33305). */
    public static function compileGetPathname(Context $context, JITVariable $receiver): Value
    {
        $pathname = self::loadPathname($context, $receiver);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $pathname
        );

        return $slot;
    }

    /** SplFileObject::getPath — dirname(__pathname) (#33305). */
    public static function compileGetPath(Context $context, JITVariable $receiver): Value
    {
        $pathname = self::loadPathname($context, $receiver);
        $dir = JitPath::dirname($context, $pathname);
        // Match SplFileInfo empty-dir when basename length equals pathname length.
        $pathLen = $context->builder->call($context->lookupFunction('__string__strlen'), $pathname);
        $name = JitPath::basename($context, $pathname);
        $nameLen = $context->builder->call($context->lookupFunction('__string__strlen'), $name);
        $noDir = $context->builder->icmp(
            Builder::INT_EQ,
            $pathLen,
            $nameLen
        );
        $empty = $context->builder->load($context->constantStringFromString(''));
        $dirOut = $context->builder->select($noDir, $empty, $dir);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $dirOut
        );

        return $slot;
    }

    /** SplFileObject::rewind — reset cursor / line / current (#33319). */
    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, self::PROP_CURSOR, $i64->constInt(0, false));
        self::storeLong($context, $obj, self::PROP_LINE, $i64->constInt(0, false));
        self::storeLong($context, $obj, self::PROP_CUR, $i64->constInt(-1, true));

        return self::voidResult($context);
    }

    /** SplFileObject::eof — cursor past last snapshot line (#33319). */
    public static function compileEof(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $atEnd = self::cursorAtEnd($context, $obj);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $atEnd);

        return $slot;
    }

    /** SplFileObject::valid — !eof under default flags (#33319). */
    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $atEnd = self::cursorAtEnd($context, $obj);
        $i1 = $context->getTypeFromString('int1');
        $ok = $context->builder->icmp(Builder::INT_EQ, $atEnd, $i1->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return $slot;
    }

    /** SplFileObject::key — current_line_num (#33319). */
    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $line = self::loadLong($context, $obj, self::PROP_LINE);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $line);

        return $slot;
    }

    /**
     * SplFileObject::current — lazy-read next snapshot line without bumping key (#33319).
     * php-src: zim_SplFileObject_current / spl_filesystem_file_read_line
     */
    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $cur = self::loadLong($context, $obj, self::PROP_CUR);
        $missing = $context->builder->icmp(Builder::INT_SLT, $cur, $i64->constInt(0, false));

        $needBb = BasicBlockHelper::append($context, 'sfo_cur_need');
        $haveBb = BasicBlockHelper::append($context, 'sfo_cur_have');
        $doneBb = BasicBlockHelper::append($context, 'sfo_cur_done');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $out = $context->builder->alloca($strPtrTy);
        $context->builder->branchIf($missing, $needBb, $haveBb);

        $context->builder->positionAtEnd($needBb);
        $ok = self::tryReadLine($context, $obj, 0);
        $okBb = BasicBlockHelper::append($context, 'sfo_cur_ok');
        $failBb = BasicBlockHelper::append($context, 'sfo_cur_fail');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($empty, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $cur2 = self::loadLong($context, $obj, self::PROP_CUR);
        $context->builder->store(self::lineStringAt($context, $obj, $cur2), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($haveBb);
        $context->builder->store(self::lineStringAt($context, $obj, $cur), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $context->builder->load($out)
        );

        return $slot;
    }

    /**
     * SplFileObject::next — drop current and bump key; next current() lazy-reads (#33319).
     */
    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $cur = self::loadLong($context, $obj, self::PROP_CUR);
        $missing = $context->builder->icmp(Builder::INT_SLT, $cur, $i64->constInt(0, false));
        $needBb = BasicBlockHelper::append($context, 'sfo_next_need');
        $afterBb = BasicBlockHelper::append($context, 'sfo_next_after');
        $context->builder->branchIf($missing, $needBb, $afterBb);

        $context->builder->positionAtEnd($needBb);
        self::tryReadLine($context, $obj, 0);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        self::storeLong($context, $obj, self::PROP_CUR, $i64->constInt(-1, true));
        $line = self::loadLong($context, $obj, self::PROP_LINE);
        self::storeLong(
            $context,
            $obj,
            self::PROP_LINE,
            $context->builder->addNoSignedWrap($line, $i64->constInt(1, false))
        );

        return self::voidResult($context);
    }

    /**
     * SplFileObject::fgets — always advances lineNum by 1 on success (Zend) (#33319).
     * Past-end returns "" (VM); Zend throws RuntimeException — throw can follow.
     */
    public static function compileFgets(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        // Free current before read (php-src spl_filesystem_file_read_ex).
        self::storeLong($context, $obj, self::PROP_CUR, $i64->constInt(-1, true));
        // Zend increments key on every successful fgets, including the first.
        $ok = self::tryReadLine($context, $obj, 1);

        $okBb = BasicBlockHelper::append($context, 'sfo_fgets_ok');
        $eofBb = BasicBlockHelper::append($context, 'sfo_fgets_eof');
        $doneBb = BasicBlockHelper::append($context, 'sfo_fgets_done');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $out = $context->builder->alloca($strPtrTy);
        $context->builder->branchIf($ok, $okBb, $eofBb);

        $context->builder->positionAtEnd($eofBb);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($empty, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $cur = self::loadLong($context, $obj, self::PROP_CUR);
        $context->builder->store(self::lineStringAt($context, $obj, $cur), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $context->builder->load($out)
        );

        return $slot;
    }

    private static function loadPathname(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pathSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_PATH);
        if (JITVariable::TYPE_STRING === $pathSlot->type) {
            return $context->helper->loadValue($pathSlot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $pathSlot)
        );
    }

    private static function initConstructedFromPath(Context $context, Value $obj, Value $pathStr): void
    {
        $objectType = $context->type->object;
        // NestedJIT explode-only line snapshot (#33308); concat-loop helper SIGSEGV'd.
        $ht = SplFileObjectSnapshotRuntime::snapshotPath($context, $pathStr);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT),
            $htVar,
            JITVariable::TYPE_HASHTABLE
        );
        $pathVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $pathStr);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_PATH),
            $pathVar,
            JITVariable::TYPE_STRING
        );
        // Parent SplFileInfo path props — isFile/getSize/getExtension (#33313).
        DirectoryIteratorJitHelper::initSplFileInfoPathProps(
            $context,
            $obj,
            $pathStr,
            self::CLASS_NAME,
            false
        );
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, self::PROP_CURSOR, $i64->constInt(0, false));
        self::storeLong($context, $obj, self::PROP_LINE, $i64->constInt(0, false));
        self::storeLong($context, $obj, self::PROP_CUR, $i64->constInt(-1, true));
        $objectType->markObjectConstructed($obj);
    }

    /** @return Value i1 — true when a line was loaded */
    private static function tryReadLine(Context $context, Value $obj, int $lineAdd): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $cursor = self::loadLong($context, $obj, self::PROP_CURSOR);
        $n64 = self::htCount($context, $obj);
        $past = $context->builder->icmp(Builder::INT_SGE, $cursor, $n64);

        $okBb = BasicBlockHelper::append($context, 'sfo_read_ok');
        $failBb = BasicBlockHelper::append($context, 'sfo_read_fail');
        $doneBb = BasicBlockHelper::append($context, 'sfo_read_done');
        $flag = $context->builder->alloca($i1);
        $context->builder->branchIf($past, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->store($i1->constInt(0, false), $flag);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        self::storeLong($context, $obj, self::PROP_CUR, $cursor);
        self::storeLong(
            $context,
            $obj,
            self::PROP_CURSOR,
            $context->builder->addNoSignedWrap($cursor, $i64->constInt(1, false))
        );
        if ($lineAdd > 0) {
            $line = self::loadLong($context, $obj, self::PROP_LINE);
            self::storeLong(
                $context,
                $obj,
                self::PROP_LINE,
                $context->builder->addNoSignedWrap($line, $i64->constInt($lineAdd, false))
            );
        }
        $context->builder->store($i1->constInt(1, false), $flag);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($flag);
    }

    private static function cursorAtEnd(Context $context, Value $obj): Value
    {
        $cursor = self::loadLong($context, $obj, self::PROP_CURSOR);
        $n64 = self::htCount($context, $obj);

        return $context->builder->icmp(Builder::INT_SGE, $cursor, $n64);
    }

    private static function htCount(Context $context, Value $obj): Value
    {
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->truncOrBitCast($n, $i64);
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    /**
     * Snapshot is explode("\n"); restore terminator for non-final parts so fgets matches Zend.
     */
    private static function lineStringAt(Context $context, Value $obj, Value $idx64): Value
    {
        $ht = self::htPtr($context, $obj);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->truncOrBitCast($idx64, $sizeT);
        $box = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $raw = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $box)
        );
        $n64 = self::htCount($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $last = $context->builder->sub($n64, $i64->constInt(1, false));
        $isLast = $context->builder->icmp(Builder::INT_EQ, $idx64, $last);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $out = $context->builder->alloca($strPtrTy);
        $lastBb = BasicBlockHelper::append($context, 'sfo_line_last');
        $midBb = BasicBlockHelper::append($context, 'sfo_line_mid');
        $doneBb = BasicBlockHelper::append($context, 'sfo_line_done');
        $context->builder->branchIf($isLast, $lastBb, $midBb);

        $context->builder->positionAtEnd($lastBb);
        $context->builder->store($raw, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($midBb);
        $nl = $context->builder->load($context->constantStringFromString("\n"));
        $withNl = JitStringConcat::concat($context, $raw, $nl);
        $context->builder->store($withNl, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($out);
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

        throw new \LogicException('SplFileObject method requires an object receiver');
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
            'SplFileObject path must be string, got '.JITVariable::getStringType($arg->type)
        );
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, $prop);
        if (JITVariable::TYPE_NATIVE_LONG === $slot->type) {
            return $context->helper->loadValue($slot);
        }
        if (JITVariable::TYPE_VALUE === $slot->type || JitValueBox::isValueOperand($slot)) {
            return $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $slot)
            );
        }

        throw new \LogicException("property {$prop} must be native long");
    }

    private static function storeLong(Context $context, Value $obj, string $prop, Value $i64): void
    {
        $var = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $i64);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            $var,
            JITVariable::TYPE_NATIVE_LONG
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
