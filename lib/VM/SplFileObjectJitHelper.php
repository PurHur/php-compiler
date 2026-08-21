<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitFflush;
use PHPCompiler\ext\standard\JitFgetcsv;
use PHPCompiler\ext\standard\JitFlock;
use PHPCompiler\ext\standard\JitFpassthru;
use PHPCompiler\ext\standard\JitFputcsv;
use PHPCompiler\ext\standard\JitFread;
use PHPCompiler\ext\standard\JitFseek;
use PHPCompiler\ext\standard\JitFtell;
use PHPCompiler\ext\standard\JitFtruncate;
use PHPCompiler\ext\standard\JitPath;
use PHPCompiler\ext\standard\StatFieldsJitHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FputcsvRuntime;
use PHPCompiler\JIT\Builtin\SplFileObjectSnapshotRuntime;
use PHPCompiler\JIT\Builtin\SscanfSimpleArrayApply;
use PHPCompiler\JIT\Builtin\StatPathRuntime;
use PHPCompiler\JIT\Builtin\StreamIo;
use PHPCompiler\JIT\Builtin\StreamLifecycle;
use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
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
 * fwrite/fputcsv fflush so path reads match Zend before destroy (#33400; peer #33133).
 * Iterator I/O (`current`/`key`/`valid`/`next`/`rewind`) + EOF latch (#33319).
 * getCurrentLine is the php-src fgets alias (#33321).
 * fread/fgetc on `__spl_fd` (#33332).
 * ftell/flock on `__spl_fd` (#33336) via JitFtell / JitFlock.
 * fstat via pathname + StatPath long fields (#33359).
 * fstat on `__spl_fd` (#33359) via JitFstat (thin AOT libc fileno force).
 * ftruncate on `__spl_fd` (#33348) via JitFtruncate (peer procedural #33155).
 * fflush on `__spl_fd` (#33354) via JitFflush (peer procedural #1189).
 * fpassthru on `__spl_fd` (#33358) via JitFpassthru (peer procedural #1194).
 * fputcsv on `__spl_fd` (#33340) via JitFputcsv (peer procedural #33334 / #27180).
 * fgetcsv on `__spl_fd` (#33346) via JitFgetcsv (peer procedural #33334 / #1192).
 * fseek on `__spl_fd` (#33347) via JitFseek (clears iterator line cache like rewind).
 * seek (SeekableIterator line) (#33364) — rewind + read-line loop + key bump.
 * setFlags/getFlags on `__spl_flags` (#33368).
 * setCsvControl/getCsvControl on `__spl_csv_*` (#33371); fgetcsv/fputcsv read props when args omitted.
 * setMaxLineLen/getMaxLineLen on `__spl_max_line_len` (#33377); fgets/fgetcsv use max+1 (#33378).
 * fscanf on `__spl_fd` — fgets + `__compiler_sscanf_array` (#33382).
 * hasChildren / getChildren — always false / null (#33388).
 * DROP_NEW_LINE strips trailing \\r\\n / \\n / \\r on line read (#33390).
 * Foreach walks packed `__spl_ht` ({@see SplOuterIteratorHt}).
 *
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / zim_SplFileObject_fgets /
 * zim_SplFileObject_getCurrentLine / zim_SplFileObject_fread / zim_SplFileObject_fgetc /
 * zim_SplFileObject_ftell / zim_SplFileObject_flock / zim_SplFileObject_ftruncate /
 * zim_SplFileObject_fflush / zim_SplFileObject_fpassthru / zim_SplFileObject_fputcsv /
 * zim_SplFileObject_fgetcsv / zim_SplFileObject_fseek / zim_SplFileObject_seek /
 * zim_SplFileObject_setFlags / zim_SplFileObject_getFlags /
 * zim_SplFileObject_setCsvControl / zim_SplFileObject_getCsvControl /
 * zim_SplFileObject_setMaxLineLen / zim_SplFileObject_getMaxLineLen /
 * zim_SplFileObject_fscanf / zim_SplFileObject_hasChildren / zim_SplFileObject_getChildren /
 * zim_SplFileInfo_openFile
 */
final class SplFileObjectJitHelper
{
    /** php-src SplFileObject::DROP_NEW_LINE — peer SplFileObjectBuiltin::DROP_NEW_LINE. */
    private const FLAG_DROP_NEW_LINE = 1;

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

    /** SplFileObject flags (READ_CSV / DROP_NEW_LINE / …) — php-src flags (#33368 / #33390). */
    public const PROP_FLAGS = '__spl_flags';
    /** SplFileObject::max_line_len — php-src max_line_len (#33377). */
    public const PROP_MAX_LINE_LEN = '__spl_max_line_len';

    /**
     * Local EOF latch — AOT `__compiler_feof` is wrong after fopen (always 1);
     * track from failed fgets instead (#33319).
     */
    public const PROP_AT_EOF = '__spl_at_eof';

    /** CSV separator — php-src intern->u.file.separator (#33371). */
    public const PROP_CSV_SEP = '__spl_csv_sep';

    /** CSV enclosure — php-src intern->u.file.enclosure (#33371). */
    public const PROP_CSV_ENC = '__spl_csv_enc';

    /** CSV escape — php-src intern->u.file.escape (#33371). */
    public const PROP_CSV_ESC = '__spl_csv_esc';

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
     * SplFileObject::fstat — path-based stat array (#33359).
     * php-src: zim_SplFileObject_fstat → php_stream_stat.
     *
     * Thin AOT `__spl_fd` is a JitStreamIoKernel FILE* id — NestedJIT VmFs::fstat
     * cannot resolve it. NestedJIT HashTable returns are also not real
     * `__hashtable__*` under thin AOT (peer getrusage #27551). Materialize via
     * StatPath long fields + `__hashtable__setLongAt` / `__hashtable__setStringKeyLong`
     * (peer SplFileInfo::getSize).
     */
    public static function compileFstat(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        StatPathRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'splfo_fstat_after_statpath');

        $obj = self::loadObject($context, $receiver);
        $path = self::loadStringProp($context, $obj, self::PROP_PATH);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $fieldFn = $context->lookupFunction(StatPathRuntime::FN_LONG_FIELD);
        $size = $context->builder->call(
            $fieldFn,
            $path,
            $zero,
            $i64->constInt(StatFieldsJitHelper::FIELD_SIZE, false)
        );
        $failed = $context->builder->icmp(Builder::INT_SLT, $size, $zero);
        $failBb = BasicBlockHelper::append($context, 'splfo_fstat_fail');
        $okBb = BasicBlockHelper::append($context, 'splfo_fstat_ok');
        $doneBb = BasicBlockHelper::append($context, 'splfo_fstat_done');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setLongAt = $context->lookupFunction('__hashtable__setLongAt');
        $setKeyLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $fields = [
            [0, 'dev', StatFieldsJitHelper::FIELD_DEV],
            [1, 'ino', StatFieldsJitHelper::FIELD_INO],
            [2, 'mode', StatFieldsJitHelper::FIELD_MODE],
            [4, 'uid', StatFieldsJitHelper::FIELD_UID],
            [5, 'gid', StatFieldsJitHelper::FIELD_GID],
            [7, 'size', StatFieldsJitHelper::FIELD_SIZE],
            [8, 'atime', StatFieldsJitHelper::FIELD_ATIME],
            [9, 'mtime', StatFieldsJitHelper::FIELD_MTIME],
            [10, 'ctime', StatFieldsJitHelper::FIELD_CTIME],
        ];
        $values = [];
        foreach ($fields as [$idx, $name, $fieldId]) {
            if (StatFieldsJitHelper::FIELD_SIZE === $fieldId) {
                $values[$idx] = [$name, $size];
                continue;
            }
            $values[$idx] = [
                $name,
                $context->builder->call(
                    $fieldFn,
                    $path,
                    $zero,
                    $i64->constInt($fieldId, false)
                ),
            ];
        }
        foreach ([3 => 'nlink', 6 => 'rdev', 11 => 'blksize', 12 => 'blocks'] as $idx => $name) {
            $values[$idx] = [$name, $zero];
        }
        ksort($values);
        foreach ($values as $idx => [$name, $val]) {
            $context->builder->call($setLongAt, $ht, $i64->constInt($idx, false), $val);
            $key = $context->builder->load($context->constantStringFromString($name));
            $context->builder->call($setKeyLong, $ht, $key, $val);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
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
     * SplFileObject::fflush — php_stream_flush on live handle (#33354).
     * php-src: zim_SplFileObject_fflush
     */
    public static function compileFflush(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        StreamLifecycle::ensureLinked($context);
        $handle = self::loadFd($context, $receiver);
        $ok = JitFflush::invoke($context, $handle);
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
     * SplFileObject::fpassthru — dump remaining stream to stdout (#33358).
     * php-src: zim_SplFileObject_fpassthru → php_stream_passthru
     */
    public static function compileFpassthru(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        // spl_filesystem_file_free_line — drop cached iterator line before consume.
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $handle = self::loadFd($context, $receiver);

        return JitFpassthru::invoke($context, $handle);
    }

    /**
     * SplFileObject::fwrite — write to live handle (#33318).
     * php-src: zim_SplFileObject_fwrite
     *
     * Thin AOT libc FILE* buffers; fflush so path reads match Zend before destroy (#33400).
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
        // Zend php_stream_write is visible on the path; libc fwrite is not until fflush/fclose.
        // Call ABI directly — JitFflush::invoke re-enters forceLibcStreamPositionAbis mid-block.
        $context->builder->call($context->lookupFunction('__compiler_fflush'), $handle);
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
        $obj = self::loadObject($context, $receiver);
        $handle = self::loadFd($context, $receiver);
        $fields = self::loadFieldsHashtable($context, $fieldsArg);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        // Omitted CSV args → object setCsvControl props (#33371); peer php-src uses intern.
        $separator = self::loadStringProp($context, $obj, self::PROP_CSV_SEP);
        $enclosure = self::loadStringProp($context, $obj, self::PROP_CSV_ENC);
        $escape = self::loadStringProp($context, $obj, self::PROP_CSV_ESC);
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

        $written = JitFputcsv::invoke($context, $handle, $fields, $separator, $enclosure, $escape, $eol);
        // Same libc buffering as fwrite — path reads must see the CSV line (#33400).
        // Direct ABI call (ensureStreamAbis already linked); avoid JitFflush::invoke mid-block.
        $context->builder->call($context->lookupFunction('__compiler_fflush'), $handle);

        return $written;
    }

    /**
     * SplFileObject::fgetcsv — read + parse CSV row on live handle (#33346).
     * php-src: zim_SplFileObject_fgetcsv → spl_filesystem_file_read_csv
     */
    public static function compileFgetcsv(
        Context $context,
        JITVariable $receiver,
        ?JITVariable $separatorArg = null,
        ?JITVariable $enclosureArg = null,
        ?JITVariable $escapeArg = null
    ): Value {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        // spl_filesystem_file_free_line — drop cached iterator line before CSV read.
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $handle = self::loadFd($context, $receiver);
        // length < 1 → JitFgetcsv default cap; else max_line_len+1 (#33378 / #19665).
        $maxLine = self::loadLongProp($context, $obj, self::PROP_MAX_LINE_LEN);
        $length = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $maxLine, $i64->constInt(0, false)),
            $context->builder->addNoSignedWrap($maxLine, $i64->constInt(1, false)),
            $i64->constInt(-1, true)
        );
        // Omitted args → setCsvControl props (#33371); peer php-src intern->u.file.*.
        $separator = self::loadStringProp($context, $obj, self::PROP_CSV_SEP);
        $enclosure = self::loadStringProp($context, $obj, self::PROP_CSV_ENC);
        $escape = self::loadStringProp($context, $obj, self::PROP_CSV_ESC);
        if (null !== $separatorArg && !NamedOptionalCallArgs::isOmittedOptional($separatorArg)) {
            $separator = JitStringArg::lower($context, $separatorArg, 'SplFileObject::fgetcsv() separator');
        }
        if (null !== $enclosureArg && !NamedOptionalCallArgs::isOmittedOptional($enclosureArg)) {
            $enclosure = JitStringArg::lower($context, $enclosureArg, 'SplFileObject::fgetcsv() enclosure');
        }
        if (null !== $escapeArg && !NamedOptionalCallArgs::isOmittedOptional($escapeArg)) {
            $escape = JitStringArg::lower($context, $escapeArg, 'SplFileObject::fgetcsv() escape');
        }

        return JitFgetcsv::invoke($context, $handle, $length, $separator, $enclosure, $escape);
    }

    /**
     * SplFileObject::fscanf — formatted input from live handle (#33382, #33389).
     * php-src: zim_SplFileObject_fscanf → php_stream_get_line + php_sscanf_internal
     *
     * Thin AOT cannot NestedJIT VmSscanf on libc-fgets strings (#27663). Array and by-ref
     * modes with a compile-time whitespace/%d/%s format use {@see SscanfSimpleArrayApply}.
     * By-ref EOF returns int -1 (SplFileObject; not procedural false).
     *
     * @param list<JITVariable> $outArgs
     */
    public static function compileFscanf(
        Context $context,
        JITVariable $receiver,
        JITVariable $formatArg,
        JITVariable ...$outArgs
    ): Value {
        $fmtLit = $formatArg->compileTimeString ?? null;
        $specs = null !== $fmtLit ? self::parseSimpleScanfSpecs($fmtLit) : null;
        if (null === $specs) {
            throw new \LogicException(
                'SplFileObject::fscanf() thin-AOT needs compile-time %d/%s format (#33382/#33389)'
            );
        }
        if ([] !== $outArgs && \count($outArgs) !== \count($specs)) {
            throw new \LogicException(
                'SplFileObject::fscanf() by-ref arity must match conversion specs (#33389)'
            );
        }
        $byRef = [] !== $outArgs;
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $handle = self::loadFd($context, $receiver);

        $strPtr = $context->getTypeFromString('__string__*');
        $line = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            self::fgetsBufferLen($context, $obj)
        );
        $slotAlloca = $context->builder->alloca($context->getTypeFromString('__value__*'));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        $eofBb = $fn->appendBasicBlock('splfo_fscanf_eof');
        $nonEmptyBb = $fn->appendBasicBlock('splfo_fscanf_nonempty');
        $scanBb = $fn->appendBasicBlock('splfo_fscanf_scan');
        $joinBb = $fn->appendBasicBlock('splfo_fscanf_join');
        $context->builder->branchIf($isNull, $eofBb, $nonEmptyBb);

        $context->builder->positionAtEnd($eofBb);
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(1, false));
        $eofSlot = JitValueBox::alloc($context);
        if ($byRef) {
            // php-src SplFileObject::fscanf by-ref at EOF → -1 (not false).
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $eofSlot),
                $i64->constInt(-1, true)
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                JitValueBox::pointer($context, $eofSlot),
                $context->getTypeFromString('int32')->constInt(0, false)
            );
        }
        $context->builder->store($eofSlot, $slotAlloca);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($nonEmptyBb);
        $lineLen = $context->builder->call($context->lookupFunction('__string__strlen'), $line);
        $empty = $context->builder->icmp(Builder::INT_EQ, $lineLen, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $eofBb, $scanBb);

        $context->builder->positionAtEnd($scanBb);
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        SscanfSimpleArrayApply::ensureLinked($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        }
        $okSlot = JitValueBox::alloc($context);
        if ($byRef) {
            $assigned = SscanfSimpleArrayApply::invokeAssign($context, $line, $specs, $outArgs);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $okSlot),
                $assigned
            );
        } else {
            $ht = SscanfSimpleArrayApply::invoke($context, $line, $specs);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $okSlot),
                $ht
            );
        }
        $context->builder->store($okSlot, $slotAlloca);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);

        return $context->builder->load($slotAlloca);
    }

    /**
     * @return list<'d'|'s'>|null
     */
    private static function parseSimpleScanfSpecs(string $format): ?array
    {
        $len = \strlen($format);
        $i = 0;
        $specs = [];
        while ($i < $len) {
            $ch = $format[$i];
            if ('%' === $ch) {
                if ($i + 1 >= $len) {
                    return null;
                }
                ++$i;
                if ('%' === $format[$i]) {
                    ++$i;
                    continue;
                }
                while ($i < $len && \in_array($format[$i], ['l', 'h', 'z', 't'], true)) {
                    ++$i;
                }
                if ($i >= $len || ('d' !== $format[$i] && 's' !== $format[$i])) {
                    return null;
                }
                $specs[] = $format[$i];
                ++$i;
                continue;
            }
            if (\ctype_space($ch)) {
                ++$i;
                continue;
            }

            return null;
        }

        return [] === $specs ? null : $specs;
    }

    /**
     * SplFileObject::setCsvControl — store separator/enclosure/escape (#33371).
     * php-src: zim_SplFileObject_setCsvControl — optional args keep prior values.
     */
    public static function compileSetCsvControl(
        Context $context,
        JITVariable $receiver,
        ?JITVariable $separatorArg = null,
        ?JITVariable $enclosureArg = null,
        ?JITVariable $escapeArg = null
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $separator = self::loadStringProp($context, $obj, self::PROP_CSV_SEP);
        $enclosure = self::loadStringProp($context, $obj, self::PROP_CSV_ENC);
        $escape = self::loadStringProp($context, $obj, self::PROP_CSV_ESC);
        if (null !== $separatorArg && !NamedOptionalCallArgs::isOmittedOptional($separatorArg)) {
            $separator = JitStringArg::lower($context, $separatorArg, 'SplFileObject::setCsvControl() separator');
        }
        if (null !== $enclosureArg && !NamedOptionalCallArgs::isOmittedOptional($enclosureArg)) {
            $enclosure = JitStringArg::lower($context, $enclosureArg, 'SplFileObject::setCsvControl() enclosure');
        }
        if (null !== $escapeArg && !NamedOptionalCallArgs::isOmittedOptional($escapeArg)) {
            $escape = JitStringArg::lower($context, $escapeArg, 'SplFileObject::setCsvControl() escape');
        }
        self::storeStringProp($context, $obj, self::PROP_CSV_SEP, $separator);
        self::storeStringProp($context, $obj, self::PROP_CSV_ENC, $enclosure);
        self::storeStringProp($context, $obj, self::PROP_CSV_ESC, $escape);

        return self::voidResult($context);
    }

    /**
     * SplFileObject::getCsvControl — packed [separator, enclosure, escape] (#33371).
     * php-src: zim_SplFileObject_getCsvControl — ZEND_PARSE_PARAMETERS_NONE
     */
    public static function compileGetCsvControl(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $separator = self::loadStringProp($context, $obj, self::PROP_CSV_SEP);
        $enclosure = self::loadStringProp($context, $obj, self::PROP_CSV_ENC);
        $escape = self::loadStringProp($context, $obj, self::PROP_CSV_ESC);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $i64 = $context->getTypeFromString('int64');
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $context->builder->call($setStringAt, $ht, $i64->constInt(0, false), $separator);
        $context->builder->call($setStringAt, $ht, $i64->constInt(1, false), $enclosure);
        $context->builder->call($setStringAt, $ht, $i64->constInt(2, false), $escape);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $ht
        );

        return $slot;
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

    /**
     * SplFileObject::hasChildren — always false (#33388).
     * php-src: zim_SplFileObject_hasChildren — RETURN_FALSE
     */
    public static function compileHasChildren(Context $context, JITVariable $receiver): Value
    {
        self::loadObject($context, $receiver);
        $slot = JitValueBox::alloc($context);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $i32->constInt(0, false)
        );

        return $slot;
    }

    /**
     * SplFileObject::getChildren — always null (#33388).
     * php-src: zim_SplFileObject_getChildren
     */
    public static function compileGetChildren(Context $context, JITVariable $receiver): Value
    {
        self::loadObject($context, $receiver);

        return self::voidResult($context);
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

    /**
     * SplFileObject::seek — SeekableIterator line seek (#33364).
     * php-src: zim_SplFileObject_seek — rewind, read_line $line times, bump key (default flags).
     */
    public static function compileSeek(
        Context $context,
        JITVariable $receiver,
        JITVariable $lineArg
    ): Value {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $line = JitLongArg::lower($context, $lineArg, 'SplFileObject::seek() line');
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->icmp(Builder::INT_SGE, $line, $i64->constInt(0, false)),
            'splfo_seek_line',
            'SplFileObject::seek(): Argument #1 ($line) must be greater than or equal to 0'
        );

        // Rewind + clear iterator cache (same as compileRewind).
        $handle = self::loadFd($context, $receiver);
        $context->builder->call(
            $context->lookupFunction('__compiler_fseek'),
            $handle,
            $i64->constInt(0, false),
            $i64->constInt(0, false)
        );
        self::storeLongProp($context, $obj, self::PROP_LINE, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(0, false));
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty);

        $iSlot = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $loopHead = $fn->appendBasicBlock('splfo_seek_head');
        $loopBody = $fn->appendBasicBlock('splfo_seek_body');
        $afterLoop = $fn->appendBasicBlock('splfo_seek_after');
        $earlyDone = $fn->appendBasicBlock('splfo_seek_eof');
        $bumpBb = $fn->appendBasicBlock('splfo_seek_bump');
        $endBb = $fn->appendBasicBlock('splfo_seek_end');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $line);
        $context->builder->branchIf($done, $afterLoop, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        // php-src/VM: fail only when already at EOF before the read attempt; a NULL
        // get_line while !eof is still SUCCESS with empty current_line (#24331).
        $atEofBefore = self::loadLongProp($context, $obj, self::PROP_AT_EOF);
        $alreadyEof = $context->builder->icmp(Builder::INT_NE, $atEofBefore, $i64->constInt(0, false));
        $doRead = $fn->appendBasicBlock('splfo_seek_do_rd');
        $context->builder->branchIf($alreadyEof, $earlyDone, $doRead);

        $context->builder->positionAtEnd($doRead);
        // Match SplFileObjectStorage::readLine — lineAdd=1 when a prior line is cached.
        $has = self::loadLongProp($context, $obj, self::PROP_HAS);
        $hadLine = $context->builder->icmp(Builder::INT_NE, $has, $i64->constInt(0, false));
        $lineAdd1 = $fn->appendBasicBlock('splfo_seek_add1');
        $lineAdd0 = $fn->appendBasicBlock('splfo_seek_add0');
        $afterRead = $fn->appendBasicBlock('splfo_seek_after_rd');
        $context->builder->branchIf($hadLine, $lineAdd1, $lineAdd0);

        $context->builder->positionAtEnd($lineAdd1);
        self::emitSeekReadLine($context, $receiver, 1);
        $context->builder->branch($afterRead);

        $context->builder->positionAtEnd($lineAdd0);
        self::emitSeekReadLine($context, $receiver, 0);
        $context->builder->branch($afterRead);

        $context->builder->positionAtEnd($afterRead);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($iSlot), $i64->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($earlyDone);
        $context->builder->branch($endBb);

        $context->builder->positionAtEnd($afterLoop);
        // Default flags (no READ_AHEAD): bump key and drop cached line (#25321 / php-src).
        $needBump = $context->builder->icmp(Builder::INT_SGT, $line, $i64->constInt(0, false));
        $context->builder->branchIf($needBump, $bumpBb, $endBb);

        $context->builder->positionAtEnd($bumpBb);
        $prev = self::loadLongProp($context, $obj, self::PROP_LINE);
        self::storeLongProp(
            $context,
            $obj,
            self::PROP_LINE,
            $context->builder->addNoSignedWrap($prev, $i64->constInt(1, false))
        );
        self::storeLongProp($context, $obj, self::PROP_HAS, $i64->constInt(0, false));
        $empty2 = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty2);
        $context->builder->branch($endBb);

        $context->builder->positionAtEnd($endBb);

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
     * SplFileObject::setFlags — store flags int (#33368).
     * php-src: zim_SplFileObject_setFlags — ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG
     */
    public static function compileSetFlags(
        Context $context,
        JITVariable $receiver,
        JITVariable $flagsArg
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $flags = JitLongArg::lower($context, $flagsArg, 'SplFileObject::setFlags() flags');
        self::storeLongProp($context, $obj, self::PROP_FLAGS, $flags);

        return self::voidResult($context);
    }

    /**
     * SplFileObject::getFlags — read flags int (#33368).
     * php-src: zim_SplFileObject_getFlags — ZEND_PARSE_PARAMETERS_NONE
     */
    public static function compileGetFlags(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $flags = self::loadLongProp($context, $obj, self::PROP_FLAGS);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $flags
        );

        return $slot;
    }

    /**
     * SplFileObject::setMaxLineLen — store max_line_len (#33377).
     * php-src: zim_SplFileObject_setMaxLineLen — ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG
     */
    public static function compileSetMaxLineLen(
        Context $context,
        JITVariable $receiver,
        JITVariable $lenArg
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $len = JitLongArg::lower($context, $lenArg, 'SplFileObject::setMaxLineLen() max_len');
        self::storeLongProp($context, $obj, self::PROP_MAX_LINE_LEN, $len);

        return self::voidResult($context);
    }

    /**
     * SplFileObject::getMaxLineLen — read max_line_len (#33377).
     * php-src: zim_SplFileObject_getMaxLineLen — ZEND_PARSE_PARAMETERS_NONE
     */
    public static function compileGetMaxLineLen(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $len = self::loadLongProp($context, $obj, self::PROP_MAX_LINE_LEN);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $len
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
            self::fgetsBufferLen($context, $obj)
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
        $flags = self::loadLongProp($context, $obj, self::PROP_FLAGS);
        $line = self::emitApplyDropNewLine($context, $line, $flags);
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

    /**
     * php-src DROP_NEW_LINE — strip trailing \\r\\n / \\n / \\r (#33390).
     * Peer: SplFileObjectStorage::applyDropNewLine. Fresh fgets buffer is separated then length-shrunk.
     */
    private static function emitApplyDropNewLine(Context $context, Value $line, Value $flags): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $context->builder->getInsertBlock()->getParent();
        $needBb = $fn->appendBasicBlock('splfo_dnl_need');
        $doneBb = $fn->appendBasicBlock('splfo_dnl_done');
        $outSlot = $context->builder->alloca($strPtr);
        $context->builder->store($line, $outSlot);

        $masked = $context->builder->and($flags, $i64->constInt(self::FLAG_DROP_NEW_LINE, false));
        $want = $context->builder->icmp(Builder::INT_NE, $masked, $i64->constInt(0, false));
        $context->builder->branchIf($want, $needBb, $doneBb);

        $context->builder->positionAtEnd($needBb);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $line
        );
        $context->builder->store($owned, $outSlot);

        $crlf = $context->builder->load($context->constantStringFromString("\r\n"));
        $isCrlf = JitStringCompare::suffixIdentical($context, $owned, $crlf);
        $crlfBb = $fn->appendBasicBlock('splfo_dnl_crlf');
        $checkOneBb = $fn->appendBasicBlock('splfo_dnl_check_one');
        $context->builder->branchIf($isCrlf, $crlfBb, $checkOneBb);

        $context->builder->positionAtEnd($crlfBb);
        self::emitShrinkStringLen($context, $owned, 2);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkOneBb);
        $nl = $context->builder->load($context->constantStringFromString("\n"));
        $isNl = JitStringCompare::suffixIdentical($context, $owned, $nl);
        $cr = $context->builder->load($context->constantStringFromString("\r"));
        $isCr = JitStringCompare::suffixIdentical($context, $owned, $cr);
        $isOne = $context->builder->or($isNl, $isCr);
        $oneBb = $fn->appendBasicBlock('splfo_dnl_one');
        $context->builder->branchIf($isOne, $oneBb, $doneBb);

        $context->builder->positionAtEnd($oneBb);
        self::emitShrinkStringLen($context, $owned, 1);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($outSlot);
    }

    /** Shrink `__string__` length in place (owned buffer after `__string__separate`). */
    private static function emitShrinkStringLen(Context $context, Value $str, int $by): void
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $lenPtr = $context->builder->structGep($str, $map['length']);
        $len = $context->builder->load($lenPtr);
        $context->builder->store(
            $context->builder->sub($len, $i64->constInt($by, false)),
            $lenPtr
        );
    }

    /**
     * Seek read_line — NULL get_line while !eof is empty-line SUCCESS + lineAdd (VM #24331).
     */
    private static function emitSeekReadLine(
        Context $context,
        JITVariable $receiver,
        int $lineAdd
    ): void {
        self::ensureStreamAbis($context);
        $obj = self::loadObject($context, $receiver);
        $handle = self::loadFd($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            self::fgetsBufferLen($context, $obj)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        $nullBb = $fn->appendBasicBlock('splfo_seek_rd_null');
        $okBb = $fn->appendBasicBlock('splfo_seek_rd_ok');
        $joinBb = $fn->appendBasicBlock('splfo_seek_rd_join');
        $lineSlot = $context->builder->alloca($strPtr);
        $context->builder->branchIf($isNull, $nullBb, $okBb);

        $context->builder->positionAtEnd($nullBb);
        // VM: false === fgets while !feof → empty current_line SUCCESS.
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($empty, $lineSlot);
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(1, false));
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($okBb);
        $flags = self::loadLongProp($context, $obj, self::PROP_FLAGS);
        $stripped = self::emitApplyDropNewLine($context, $raw, $flags);
        $context->builder->store($stripped, $lineSlot);
        self::storeLongProp($context, $obj, self::PROP_AT_EOF, $i64->constInt(0, false));
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $line = $context->builder->load($lineSlot);
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
        self::storeLongProp($context, $obj, self::PROP_FLAGS, $i64->constInt(0, false));
        self::storeLongProp($context, $obj, self::PROP_MAX_LINE_LEN, $i64->constInt(0, false));
        $empty = $context->builder->load($context->constantStringFromString(''));
        self::storeStringProp($context, $obj, self::PROP_CUR_LINE, $empty);
        // php-src defaults: separator=',', enclosure='"', escape='\\' (#33371).
        self::storeStringProp(
            $context,
            $obj,
            self::PROP_CSV_SEP,
            $context->builder->load($context->constantStringFromString(','))
        );
        self::storeStringProp(
            $context,
            $obj,
            self::PROP_CSV_ENC,
            $context->builder->load($context->constantStringFromString('"'))
        );
        self::storeStringProp(
            $context,
            $obj,
            self::PROP_CSV_ESC,
            $context->builder->load($context->constantStringFromString('\\'))
        );
        $objectType->markObjectConstructed($obj);
    }

    /**
     * php-src max_line_len+1 get_line buffer; 0 → default 8192 (#33378 / #19665).
     *
     * @return Value i64
     */
    private static function fgetsBufferLen(Context $context, Value $obj): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $max = self::loadLongProp($context, $obj, self::PROP_MAX_LINE_LEN);
        $capped = $context->builder->icmp(Builder::INT_SGT, $max, $i64->constInt(0, false));

        return $context->builder->select(
            $capped,
            $context->builder->addNoSignedWrap($max, $i64->constInt(1, false)),
            $i64->constInt(8192, false)
        );
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
