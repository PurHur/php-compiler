<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin user-script AOT {@see __compiler_stream_get_meta_data} (#27659).
 *
 * NestedJIT {@see StreamMetaJitHelper} → {@see VmFs} never sees {@see StreamGlobalsJit}
 * slots that {@see JitStreamIoKernel} fopen fills (peer {@see JitStreamLifecycleKernel} #27186).
 * Build the meta hashtable in LLVM from path + FILE* (php-src streamsfuncs.c keys).
 */
final class JitStreamMetaThinAot
{
    private const MAX_HANDLES = 256;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    public static function implementGetMetaData(Context $context): void
    {
        StreamGlobalsJit::ensureGlobals($context);
        StreamGlobalsJit::implement($context);
        self::ensureExternals($context);

        $abi = '__compiler_stream_get_meta_data';
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $i64);

        $fn = $context->module->getNamedFunction($abi);
        if (null === $fn) {
            $fn = $context->module->addFunction($abi, $ft);
        } elseif ($fn->countBasicBlocks() > 0) {
            foreach (\array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        self::emitGetMetaData($context, $fn);
        $context->registerFunction($abi, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitGetMetaData(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgmd_thin_entry');
        $fail = $fn->appendBasicBlock('sgmd_thin_fail');
        $lookup = $fn->appendBasicBlock('sgmd_thin_lookup');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI64 = $i64->constInt(0, false);
        $nullPtr = $i8p->constNull();
        $nullHt = $htPtr->constNull();

        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $handle, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(self::MAX_HANDLES, false))
        );
        $context->builder->branchIf($inRange, $lookup, $fail);

        $context->builder->positionAtEnd($lookup);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $hasFp = $context->builder->icmp(Builder::INT_NE, $fp, $nullPtr);
        $pathBb = $fn->appendBasicBlock('sgmd_thin_path');
        $context->builder->branchIf($hasFp, $pathBb, $fail);

        $context->builder->positionAtEnd($pathBb);
        $path = self::loadPtrSlot($context, self::GLOBAL_PATHS, $handle);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $emptyPathBb = $fn->appendBasicBlock('sgmd_thin_empty_path');
        $classBb = $fn->appendBasicBlock('sgmd_thin_class');
        $context->builder->branchIf($pathNull, $emptyPathBb, $classBb);

        // tmpfile() / pathless slot — plainfile-shaped meta with empty uri.
        $context->builder->positionAtEnd($emptyPathBb);
        $eofEmpty = self::feofBool($context, $fp);
        $htEmpty = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::fillPlainMeta(
            $context,
            $htEmpty,
            self::literalString($context, ''),
            self::literalString($context, 'plainfile'),
            self::literalString($context, 'STDIO'),
            self::literalString($context, 'r+b'),
            $eofEmpty,
            true
        );
        $context->builder->returnValue($htEmpty);

        $context->builder->positionAtEnd($classBb);
        $isMemory = self::prefixMatch($context, $path, 'php://memory', 12);
        $memBb = $fn->appendBasicBlock('sgmd_thin_memory');
        $tempCheck = $fn->appendBasicBlock('sgmd_thin_temp_check');
        $context->builder->branchIf($isMemory, $memBb, $tempCheck);

        $context->builder->positionAtEnd($memBb);
        $eofMem = self::feofBool($context, $fp);
        $uriMem = self::stringFromCstr($context, $path);
        $htMem = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::fillPlainMeta(
            $context,
            $htMem,
            $uriMem,
            self::literalString($context, 'PHP'),
            self::literalString($context, 'MEMORY'),
            self::literalString($context, 'w+b'),
            $eofMem,
            true
        );
        $context->builder->returnValue($htMem);

        $context->builder->positionAtEnd($tempCheck);
        $isTemp = self::prefixMatch($context, $path, 'php://temp', 10);
        $tempBb = $fn->appendBasicBlock('sgmd_thin_temp');
        $phpCheck = $fn->appendBasicBlock('sgmd_thin_php_check');
        $context->builder->branchIf($isTemp, $tempBb, $phpCheck);

        // php-src php_stream_temp — no timed_out/blocked/eof (#17928).
        $context->builder->positionAtEnd($tempBb);
        $uriTemp = self::stringFromCstr($context, $path);
        $htTemp = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setString($context, $htTemp, 'wrapper_type', self::literalString($context, 'PHP'));
        self::setString($context, $htTemp, 'stream_type', self::literalString($context, 'TEMP'));
        self::setString($context, $htTemp, 'mode', self::literalString($context, 'w+b'));
        self::setLong($context, $htTemp, 'unread_bytes', $i64->constInt(0, false));
        self::setBool($context, $htTemp, 'seekable', true);
        self::setString($context, $htTemp, 'uri', $uriTemp);
        $context->builder->returnValue($htTemp);

        $context->builder->positionAtEnd($phpCheck);
        $isPhp = self::prefixMatch($context, $path, 'php://', 6);
        $phpBb = $fn->appendBasicBlock('sgmd_thin_php');
        $dataCheck = $fn->appendBasicBlock('sgmd_thin_data_check');
        $context->builder->branchIf($isPhp, $phpBb, $dataCheck);

        $context->builder->positionAtEnd($phpBb);
        $eofPhp = self::feofBool($context, $fp);
        $uriPhp = self::stringFromCstr($context, $path);
        $htPhp = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::fillPlainMeta(
            $context,
            $htPhp,
            $uriPhp,
            self::literalString($context, 'PHP'),
            self::literalString($context, 'STDIO'),
            self::literalString($context, 'r+b'),
            $eofPhp,
            false
        );
        $context->builder->returnValue($htPhp);

        $context->builder->positionAtEnd($dataCheck);
        $isData = self::prefixMatch($context, $path, 'data://', 7);
        $dataBb = $fn->appendBasicBlock('sgmd_thin_data');
        $plainBb = $fn->appendBasicBlock('sgmd_thin_plain');
        $context->builder->branchIf($isData, $dataBb, $plainBb);

        $context->builder->positionAtEnd($dataBb);
        $uriData = self::stringFromCstr($context, $path);
        $htData = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setString($context, $htData, 'wrapper_type', self::literalString($context, 'RFC2397'));
        self::setString($context, $htData, 'stream_type', self::literalString($context, 'RFC2397'));
        self::setString($context, $htData, 'mode', self::literalString($context, 'r+b'));
        self::setLong($context, $htData, 'unread_bytes', $i64->constInt(0, false));
        self::setBool($context, $htData, 'seekable', true);
        self::setString($context, $htData, 'uri', $uriData);
        $context->builder->returnValue($htData);

        $context->builder->positionAtEnd($plainBb);
        $eofPlain = self::feofBool($context, $fp);
        $uriPlain = self::stringFromCstr($context, $path);
        $htPlain = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::fillPlainMeta(
            $context,
            $htPlain,
            $uriPlain,
            self::literalString($context, 'plainfile'),
            self::literalString($context, 'STDIO'),
            self::literalString($context, 'r+b'),
            $eofPlain,
            true
        );
        $context->builder->returnValue($htPlain);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullHt);
    }

    private static function fillPlainMeta(
        Context $context,
        Value $ht,
        Value $uri,
        Value $wrapperType,
        Value $streamType,
        Value $mode,
        Value $eofBool,
        bool $seekable
    ): void {
        $i64 = $context->getTypeFromString('int64');
        self::setBool($context, $ht, 'timed_out', false);
        self::setBool($context, $ht, 'blocked', true);
        self::setBoolValue($context, $ht, 'eof', $eofBool);
        self::setString($context, $ht, 'wrapper_type', $wrapperType);
        self::setString($context, $ht, 'stream_type', $streamType);
        self::setString($context, $ht, 'mode', $mode);
        self::setLong($context, $ht, 'unread_bytes', $i64->constInt(0, false));
        self::setBool($context, $ht, 'seekable', $seekable);
        self::setString($context, $ht, 'uri', $uri);
    }

    private static function feofBool(Context $context, Value $fp): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $feof = $context->builder->call($context->lookupFunction('feof'), $fp);

        return $context->builder->icmp(Builder::INT_NE, $feof, $i32->constInt(0, false));
    }

    private static function prefixMatch(Context $context, Value $path, string $prefix, int $len): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, $prefix),
            $sizeT->constInt($len, false)
        );

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    private static function stringFromCstr(Context $context, Value $cstr): Value
    {
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $i64 = $context->getTypeFromString('int64');
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $cstr
        );
    }

    private static function literalString(Context $context, string $text): Value
    {
        return $context->builder->load($context->constantStringFromString($text));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function setString(Context $context, Value $ht, string $key, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            self::literalString($context, $key),
            $value
        );
    }

    private static function setLong(Context $context, Value $ht, string $key, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            self::literalString($context, $key),
            $long
        );
    }

    private static function setBool(Context $context, Value $ht, string $key, bool $value): void
    {
        $i1 = $context->getTypeFromString('int1');
        self::setBoolValue($context, $ht, $key, $i1->constInt($value ? 1 : 0, false));
    }

    private static function setBoolValue(Context $context, Value $ht, string $key, Value $bool): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            self::literalString($context, $key),
            $bool
        );
    }

    private static function loadPtrSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamMetaThinAot: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $i64->constInt(0, false), $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function ensureExternals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');

        foreach ([
            ['feof', $i32, [$i8p]],
            ['strlen', $sizeT, [$i8p]],
            ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyBool', $void, [$htPtr, $strPtr, $i1]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }
}
