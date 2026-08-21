<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitFlock;
use PHPCompiler\ext\standard\JitFputcsv;
use PHPCompiler\ext\standard\JitFread;
use PHPCompiler\ext\standard\JitFseek;
use PHPCompiler\ext\standard\JitFtell;
use PHPCompiler\ext\standard\JitFtruncate;
use PHPCompiler\ext\standard\JitPath;
use PHPCompiler\JIT\Builtin\FputcsvRuntime;
use PHPCompiler\JIT\Builtin\SplFileObjectSnapshotRuntime;
use PHPCompiler\JIT\Builtin\StreamIo;
use PHPCompiler\JIT\Builtin\StreamLifecycle;
use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
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
 * Live stream handle `__spl_fd` for fgets/fwrite (#33318) via StreamIo/StreamRead ABIs.
 * Iterator I/O (`current`/`key`/`valid`/`next`/`rewind`) + EOF latch (#33319).
 * getCurrentLine is the php-src fgets alias (#33321).
 * fread/fgetc on `__spl_fd` (#33332).
 * ftell/flock on `__spl_fd` (#33336) via JitFtell / JitFlock.
 * ftruncate on `__spl_fd` (#33348) via JitFtruncate (peer procedural #33155).
 * fputcsv on `__spl_fd` (#33340) via JitFputcsv (peer procedural #33334 / #27180).
 * fseek on `__spl_fd` (#33347) via JitFseek (clears iterator line cache like rewind).
 * Foreach walks packed `__spl_ht` ({@see SplOuterIteratorHt}).
 *
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / zim_SplFileObject_fgets /
 * zim_SplFileObject_getCurrentLine / zim_SplFileObject_fread / zim_SplFileObject_fgetc /
 * zim_SplFileObject_ftell / zim_SplFileObject_flock / zim_SplFileObject_ftruncate /
 * zim_SplFileObject_fputcsv / zim_SplFileObject_fseek / zim_SplFileInfo_openFile
 */
final class SplFileObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_PATH = '__pathname';

    /** Live libc stream handle id ({@see StreamIoRuntime} / JitStreamIoKernel). */
    public const PROP_FD = '__spl_fd';

    /** SplFileObject::key() — php-src current_line_num (#33319). */
    public const PROP_LINE = '__spl_line_num';

    /** 1 when current_line is loaded. */
    public const PROP_HAS = '__spl_has_line';

    /** Cached current_line string. */
    public const PROP_CUR_LINE = '__spl_cur_line';

    /**
     * Local EOF latch — AOT `__compiler_feof` is wrong after fopen (always 1);
     * track from failed fgets instead (#33319).
     */
    public const PROP_AT_EOF = '__spl_at_eof';

    private const CLASS_NAME = 'SplFileObject';

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $pathArg,
        ?JITVariable $modeArg = null
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $modeLiteral = 'r';
        if (null !== $modeArg) {
            $modeLiteral = $modeArg->compileTimeString ?? null;
        }
        $mode = null !== $modeArg
            ? self::loadString($context, $modeArg)
            : $context->builder->load($context->constantStringFromString('r'));
        self::initConstructedFromPath(
            $context,
            $obj,
            self::loadString($context, $pathArg),
            $mode,
            $modeLiteral
        );

        return self::voidResult($context);
    }

    /**
     * Allocate SplFileObject and init from pathname (openFile / factories) (#33305).
     */
    public static function emitNewFromPathname(Context $context, Value $pathStr): Value
    {
        $classId = $context->type->object->lookup(self::CLASS_NAME);
        $newObj = $context->type->object->allocate($classId);
        self::initConstructedFromPath($context, $newObj, $pathStr, null);
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

    /**
     * SplFileObject::fgets — read one line from live handle; bump key (Zend) (#33318 / #33319).
     * php-src: zim_SplFileObject_fgets
     */
    public static function compileFgets(Context $context, JITVariable $receiver): Value
    {
        // Free current then read with lineAdd=1 (Zend always increments on fgets).
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));

        return self::emitReadLineToValueBox($context, $receiver, 1);
    }

    /**
     * SplFileObject::fread — binary read from live handle (#33332).
     * php-src: zim_SplFileObject_fread
     */
    public static function compileFread(
        Context $context,
        JITVariable $receiver,
        JITVariable $lengthArg
    ): Value {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        // spl_filesystem_file_free_line — drop cached iterator line.
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $handle = self::loadFd($context, $receiver);
        $length = self::loadLong($context, $lengthArg);
        JitFread::emitRuntimeLengthGuard($context, $length);

        return JitFread::invoke($context, $handle, $length);
    }

    /**
     * SplFileObject::fgetc — one char from live handle; bump key on "\\n" (#33332).
     * php-src: zim_SplFileObject_fgetc
     */
    public static function compileFgetc(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $handle = self::loadFd($context, $receiver);
        StreamRead::ensureLinked($context);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_fgetc'),
            $handle
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $failBb = $fn->appendBasicBlock('splfo_fgetc_fail');
        $okBb = $fn->appendBasicBlock('splfo_fgetc_ok');
        $doneBb = $fn->appendBasicBlock('splfo_fgetc_done');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $contents
        );
        $nl = $context->builder->load($context->constantStringFromString("\n"));
        $isNl = JitStringCompare::identical($context, $owned, $nl);
        $bumpBb = $fn->appendBasicBlock('splfo_fgetc_nl_bump');
        $writeBb = $fn->appendBasicBlock('splfo_fgetc_write');
        $context->builder->branchIf($isNl, $bumpBb, $writeBb);

        $context->builder->positionAtEnd($bumpBb);
        $prev = self::loadLongProp($context, $obj, self::PROP_LINE);
        self::storeLongProp(
            $context,
            $obj,
            self::PROP_LINE,
            $context->builder->addNoSignedWrap($prev, $i64->constInt(1, false))
        );
        $context->builder->branch($writeBb);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slot;
    }

    /**
     * SplFileObject::ftell — php_stream_tell on live handle (#33336).
     * php-src: zim_SplFileObject_ftell
     */
    public static function compileFtell(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        $handle = self::loadFd($context, $receiver);

        return JitFtell::invoke($context, $handle);
    }

    /**
     * SplFileObject::fseek — php_stream_seek on live handle (#33347).
     * php-src: zim_SplFileObject_fseek — clears current line cache like rewind.
     */
    public static function compileFseek(
        Context $context,
        JITVariable $receiver,
        JITVariable $offsetArg,
        ?JITVariable $whenceArg = null
    ): Value {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $handle = self::loadFd($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $offset = self::loadLong($context, $offsetArg);
        $whence = null !== $whenceArg
            ? self::loadLong($context, $whenceArg)
            : $i64->constInt(0, false); // SEEK_SET
        $resultPtr = JitFseek::invoke($context, $handle, $offset, $whence);
        // php-src: after seek, drop cached current_line / has_current_line / eof latch.
        self::storeLongProp($context, $obj, self::PROP_LINE, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(0, false));
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty);

        return $resultPtr;
    }

    /**
     * SplFileObject::flock — php_stream_lock on live handle (#33336).
     * php-src: zim_SplFileObject_flock
     */
    public static function compileFlock(
        Context $context,
        JITVariable $receiver,
        JITVariable $operationArg
    ): Value {
        self::ensureStreamAbis($context);
        $handle = self::loadFd($context, $receiver);
        if (JitFlock::isCompileTimeNullOperation($operationArg)) {
            $ok = JitFlock::emitCompileTimeNullOperation($context, $operationArg);
        } else {
            $operation = JitFlock::lowerOperation($context, $operationArg);
            $ok = JitFlock::invoke($context, $handle, $operation);
        }
        $i32 = $context->getTypeFromString('int32');
        $asI32 = $context->builder->zExt($ok, $i32);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $asI32
        );

        return $slot;
    }

    /**
     * SplFileObject::ftruncate — php_stream_truncate_set_size on live handle (#33348).
     * php-src: zim_SplFileObject_ftruncate
     */
    public static function compileFtruncate(
        Context $context,
        JITVariable $receiver,
        JITVariable $sizeArg
    ): Value {
        self::ensureStreamAbis($context);
        $handle = self::loadFd($context, $receiver);
        $size = self::loadLong($context, $sizeArg);
        $ok = JitFtruncate::invoke($context, $handle, $size);
        $i32 = $context->getTypeFromString('int32');
        $asI32 = $context->builder->zExt($ok, $i32);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $asI32
        );

        return $slot;
    }

    /**
     * SplFileObject::fwrite — write to live handle (#33318).
     * php-src: zim_SplFileObject_fwrite
     */
    public static function compileFwrite(
        Context $context,
        JITVariable $receiver,
        JITVariable $dataArg,
        ?JITVariable $lengthArg = null
    ): Value {
        self::ensureStreamAbis($context);
        $handle = self::loadFd($context, $receiver);
        $data = self::loadString($context, $dataArg);
        $i64 = $context->getTypeFromString('int64');
        // JitStreamIoKernel: negative length returns 0 — pass strlen when omitted.
        $length = null !== $lengthArg
            ? self::loadLong($context, $lengthArg)
            : $context->builder->call($context->lookupFunction('__string__strlen'), $data);
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_fwrite'),
            $handle,
            $data,
            $length
        );
        $slot = JitValueBox::alloc($context);
        $fail = $context->builder->icmp(Builder::INT_SLT, $written, $i64->constInt(0, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $failBb = $fn->appendBasicBlock('splfo_fwrite_fail');
        $okBb = $fn->appendBasicBlock('splfo_fwrite_ok');
        $joinBb = $fn->appendBasicBlock('splfo_fwrite_join');
        $context->builder->branchIf($fail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $i32->constInt(0, false)
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $written
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);

        return $slot;
    }

    /**
     * SplFileObject::fputcsv — format + write CSV on live handle (#33340).
     * php-src: zim_SplFileObject_fputcsv → php_fputcsv
     */
    public static function compileFputcsv(
        Context $context,
        JITVariable $receiver,
        JITVariable $fieldsArg,
        ?JITVariable $separatorArg = null,
        ?JITVariable $enclosureArg = null,
        ?JITVariable $escapeArg = null,
        ?JITVariable $eolArg = null
    ): Value {
        self::ensureStreamAbis($context);
        FputcsvRuntime::ensureLinked($context);
        $handle = self::loadFd($context, $receiver);
        $fields = self::loadFieldsHashtable($context, $fieldsArg);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $separator = $nullStr;
        $enclosure = $nullStr;
        $escape = $nullStr;
        $eol = $nullStr;
        if (null !== $separatorArg && !NamedOptionalCallArgs::isOmittedOptional($separatorArg)) {
            $separator = JitStringArg::lower($context, $separatorArg, 'SplFileObject::fputcsv() separator');
        }
        if (null !== $enclosureArg && !NamedOptionalCallArgs::isOmittedOptional($enclosureArg)) {
            $enclosure = JitStringArg::lower($context, $enclosureArg, 'SplFileObject::fputcsv() enclosure');
        }
        if (null !== $escapeArg && !NamedOptionalCallArgs::isOmittedOptional($escapeArg)) {
            $escape = JitStringArg::lower($context, $escapeArg, 'SplFileObject::fputcsv() escape');
        }
        if (null !== $eolArg && !NamedOptionalCallArgs::isOmittedOptional($eolArg)) {
            $eol = JitStringArg::lower($context, $eolArg, 'SplFileObject::fputcsv() eol');
        }

        return JitFputcsv::invoke($context, $handle, $fields, $separator, $enclosure, $escape, $eol);
    }

    /**
     * SplFileObject::eof — local latch (AOT __compiler_feof is unreliable) (#33319).
     * php-src: zim_SplFileObject_eof
     */
    public static function compileEof(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $flag = self::loadLongProp($context, $obj, self::PROP_AT_EOF);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $isEof = $context->builder->icmp(Builder::INT_NE, $flag, $i64->constInt(0, false));
        $asI32 = $context->builder->zExt($isEof, $i32);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $asI32
        );

        return $slot;
    }

    /** SplFileObject::rewind — fseek(0) + reset iterator state (#33319). */
    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $handle = self::loadFd($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__compiler_fseek'),
            $handle,
            $i64->constInt(0, false),
            $i64->constInt(0, false) // SEEK_SET
        );
        self::storeLongProp($context, $obj, self::PROP_LINE, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(0, false));
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty);

        return self::voidResult($context);
    }

    /** SplFileObject::valid — !at_eof under default flags (#33319). */
    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $flag = self::loadLongProp($context, $obj, self::PROP_AT_EOF);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ok = $context->builder->icmp(Builder::INT_EQ, $flag, $i64->constInt(0, false));
        $asI32 = $context->builder->zExt($ok, $i32);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $asI32
        );

        return $slot;
    }

    /** SplFileObject::key — current_line_num (#33319). */
    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $line = self::loadLongProp($context, $obj, self::PROP_LINE);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $line
        );

        return $slot;
    }

    /**
     * SplFileObject::current — lazy-read without bumping key (#33319).
     * php-src: zim_SplFileObject_current
     */
    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $has = self::loadLongProp($context, $obj, self::PROP_HAS);
        $missing = $context->builder->icmp(Builder::INT_EQ, $has, $i64->constInt(0, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $needBb = $fn->appendBasicBlock('splfo_cur_need');
        $haveBb = $fn->appendBasicBlock('splfo_cur_have');
        $doneBb = $fn->appendBasicBlock('splfo_cur_done');
        $slotAlloca = $context->builder->alloca($context->getTypeFromString('__value__*'));
        $context->builder->branchIf($missing, $needBb, $haveBb);

        $context->builder->positionAtEnd($needBb);
        // Lazy read: lineAdd=0 so key stays 0 on first current().
        $tmp = self::emitReadLineToValueBox($context, $receiver, 0);
        $context->builder->store($tmp, $slotAlloca);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($haveBb);
        $slot = JitValueBox::alloc($context);
        $cur = self::loadStringProp($context, $obj, self::PROP_CUR_LINE);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $cur
        );
        $context->builder->store($slot, $slotAlloca);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($slotAlloca);
    }

    /**
     * SplFileObject::next — drop current and bump key (#33319).
     * php-src: zim_SplFileObject_next
     */
    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $has = self::loadLongProp($context, $obj, self::PROP_HAS);
        $missing = $context->builder->icmp(Builder::INT_EQ, $has, $i64->constInt(0, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $needBb = $fn->appendBasicBlock('splfo_next_need');
        $afterBb = $fn->appendBasicBlock('splfo_next_after');
        $context->builder->branchIf($missing, $needBb, $afterBb);

        $context->builder->positionAtEnd($needBb);
        self::emitReadLineToValueBox($context, $receiver, 0);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $line = self::loadLongProp($context, $obj, self::PROP_LINE);
        self::storeLongProp(
            $context,
            $obj,
            self::PROP_LINE,
            $context->builder->addNoSignedWrap($line, $i64->constInt(1, false))
        );

        return self::voidResult($context);
    }

    /**
     * Read one line into PROP_CUR_LINE; bump LINE by $lineAdd; set HAS / AT_EOF.
     *
     * @return Value __value__* box (string or false)
     */
    private static function emitReadLineToValueBox(
        Context $context,
        JITVariable $receiver,
        int $lineAdd
    ): Value {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $handle = self::loadFd($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $line = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            $i64->constInt(8192, false)
        );
        $slot = JitValueBox::alloc($context);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        $eofBb = $fn->appendBasicBlock('splfo_rd_eof');
        $okBb = $fn->appendBasicBlock('splfo_rd_ok');
        $joinBb = $fn->appendBasicBlock('splfo_rd_join');
        $context->builder->branchIf($isNull, $eofBb, $okBb);

        $context->builder->positionAtEnd($eofBb);
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(1, false));
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty);
        // Match VM past-end fgets → "" (Zend throws) for thin AOT.
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $empty
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($okBb);
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(1, false));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $line);
        if ($lineAdd > 0) {
            $prev = self::loadLongProp($context, $obj, self::PROP_LINE);
            self::storeLongProp(
                $context,
                $obj,
                self::PROP_LINE,
                $context->builder->addNoSignedWrap($prev, $i64->constInt($lineAdd, false))
            );
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $line
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);

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

    private static function loadFd(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_FD);
        if (JITVariable::TYPE_NATIVE_LONG === $slot->type) {
            return $context->helper->loadValue($slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__toLong'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );
    }

    private static function ensureStreamAbis(Context $context): void
    {
        // StreamIo::ensureLinked → JitStreamIoKernel + StreamGlobals (__phpc_resolve_stream).
        StreamIo::ensureLinked($context);
        StreamRead::ensureLinked($context);
        StreamLifecycle::ensureLinked($context);
    }

    private static function initConstructedFromPath(
        Context $context,
        Value $obj,
        Value $pathStr,
        ?Value $modeStr = null,
        ?string $modeLiteral = null
    ): void {
        $objectType = $context->type->object;
        // Line snapshot is for foreach over an existing readable file (#33308).
        // Write modes (w+/a+/…) open a missing path — snapshot SIGSEGVs (#33340).
        $writeish = null !== $modeLiteral && 1 === preg_match('/^[waxc]/i', $modeLiteral);
        if ($writeish) {
            $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        } else {
            $ht = SplFileObjectSnapshotRuntime::snapshotPath($context, $pathStr);
        }
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
        // Live stream handle for fgets/fwrite/eof (#33318); peer VM SplFileObjectStorage.
        self::ensureStreamAbis($context);
        $mode = $modeStr ?? $context->builder->load($context->constantStringFromString('r'));
        $fd = $context->builder->call(
            $context->lookupFunction('__compiler_fopen'),
            $pathStr,
            $mode
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_FD),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $fd),
            JITVariable::TYPE_NATIVE_LONG
        );
        $i64 = $context->getTypeFromString('int64');
        self::storeLongProp($context, $obj, self::PROP_LINE, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(0, false));
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty);
        $objectType->markObjectConstructed($obj);
    }

    private static function loadLongProp(Context $context, Value $obj, string $prop): Value
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

    private static function storeLongProp(Context $context, Value $obj, string $prop, Value $i64): void
    {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $i64),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function loadStringProp(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, $prop);
        if (JITVariable::TYPE_STRING === $slot->type) {
            return $context->helper->loadValue($slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );
    }

    private static function storeStringProp(Context $context, Value $obj, string $prop, Value $str): void
    {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $str),
            JITVariable::TYPE_STRING
        );
    }

    private static function loadFieldsHashtable(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException('SplFileObject::fputcsv() fields must be an array (#33340)');
    }

    private static function loadLong(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            return $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        return $context->helper->loadValue($arg);
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
