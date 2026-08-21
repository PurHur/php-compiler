<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitPath;
use PHPCompiler\JIT\Builtin\SplFileObjectSnapshotRuntime;
use PHPCompiler\JIT\Builtin\StreamIo;
use PHPCompiler\JIT\Builtin\StreamLifecycle;
use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\JIT\Context;
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
 * Live stream handle `__spl_fd` for fgets/fwrite/eof (#33318) via StreamIo/StreamRead ABIs.
 * Foreach walks packed `__spl_ht` ({@see SplOuterIteratorHt}).
 *
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / zim_SplFileInfo_openFile
 */
final class SplFileObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_PATH = '__pathname';

    /** Live libc stream handle id ({@see StreamIoRuntime} / JitStreamIoKernel). */
    public const PROP_FD = '__spl_fd';

    private const CLASS_NAME = 'SplFileObject';

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $pathArg,
        ?JITVariable $modeArg = null
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $mode = null !== $modeArg
            ? self::loadString($context, $modeArg)
            : $context->builder->load($context->constantStringFromString('r'));
        self::initConstructedFromPath($context, $obj, self::loadString($context, $pathArg), $mode);

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
     * SplFileObject::fgets — read one line from live handle (#33318).
     * php-src: zim_SplFileObject_fgets
     */
    public static function compileFgets(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
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
        $eofBb = $fn->appendBasicBlock('splfo_fgets_eof');
        $okBb = $fn->appendBasicBlock('splfo_fgets_ok');
        $joinBb = $fn->appendBasicBlock('splfo_fgets_join');
        $context->builder->branchIf($isNull, $eofBb, $okBb);

        $context->builder->positionAtEnd($eofBb);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $i32->constInt(0, false)
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($okBb);
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
     * SplFileObject::eof — feof on live handle (#33318).
     * php-src: zim_SplFileObject_eof
     */
    public static function compileEof(Context $context, JITVariable $receiver): Value
    {
        self::ensureStreamAbis($context);
        $handle = self::loadFd($context, $receiver);
        $flag = $context->builder->call(
            $context->lookupFunction('__compiler_feof'),
            $handle
        );
        $i32 = $context->getTypeFromString('int32');
        // __compiler_feof → i32; __value__writeBool wants i32 (#27008).
        $isEof = $context->builder->icmp(
            Builder::INT_NE,
            $flag,
            $i32->constInt(0, false)
        );
        $asI32 = $context->builder->zExt($isEof, $i32);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $asI32
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
        ?Value $modeStr = null
    ): void {
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
        $objectType->markObjectConstructed($obj);
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
