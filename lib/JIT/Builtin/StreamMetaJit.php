<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream metadata helpers — get_meta_data / set_blocking (#6007).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_get_meta_data),
 * PHP_FUNCTION(stream_set_blocking)
 */
final class StreamMetaJit
{
    private const MAX_HANDLES = 256;

    private const O_NONBLOCK = 2048;

    private const F_GETFL = 3;

    private const F_SETFL = 4;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_get_meta_data',
        '__compiler_stream_set_blocking',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_get_meta_data');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        self::implementIfMissing($context, '__compiler_stream_get_meta_data', self::emitGetMetaData(...));
        self::implementIfMissing($context, '__compiler_stream_set_blocking', self::emitSetBlocking(...));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = match ($name) {
            '__compiler_stream_get_meta_data' => $context->context->functionType($htPtr, false, $i64),
            '__compiler_stream_set_blocking' => $context->context->functionType($i32, false, $i64, $i64),
            default => throw new \LogicException('StreamMetaJit: unknown function '.$name),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function emitGetMetaData(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_meta_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $zeroI1 = $i1->constInt(0, false);
        $oneI1 = $i1->constInt(1, false);
        $nullHt = $htPtr->constNull();
        $nullPtr = $i8p->constNull();
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('stream_meta_fail');
        $openBb = $fn->appendBasicBlock('stream_meta_open');
        $context->builder->branchIf($badHandle, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $workBb = $fn->appendBasicBlock('stream_meta_work');
        $context->builder->branchIf($notOpen, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $pathCstr = self::loadPtrSlot($context, self::GLOBAL_PATHS, $handle);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $fillBb = $fn->appendBasicBlock('stream_meta_fill');
        $context->builder->branchIf($htNull, $failBb, $fillBb);

        $context->builder->positionAtEnd($fillBb);
        $setBool = $context->lookupFunction('__hashtable__setStringKeyBool');
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');

        $context->builder->call($setBool, $ht, self::literalString($context, 'timed_out'), $zeroI1);
        $context->builder->call($setLong, $ht, self::literalString($context, 'unread_bytes'), $zero64);
        $context->builder->call($setBool, $ht, self::literalString($context, 'blocked'), $oneI1);
        $context->builder->call($setBool, $ht, self::literalString($context, 'seekable'), $oneI1);

        $eof = $context->builder->call($context->lookupFunction('feof'), $fp);
        $eofBool = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $eof, $zero32),
            $oneI1,
            $zeroI1
        );
        $context->builder->call($setBool, $ht, self::literalString($context, 'eof'), $eofBool);

        $isPhp = self::hasPrefix($context, $pathCstr, 'php://');
        $isPhpMemory = self::hasPrefix($context, $pathCstr, 'php://memory');
        $wrapperStr = $context->builder->select(
            $isPhp,
            self::literalString($context, 'PHP'),
            self::literalString($context, 'plainfile')
        );
        $context->builder->call($setString, $ht, self::literalString($context, 'wrapper_type'), $wrapperStr);

        $streamTypeStr = $context->builder->select(
            $isPhpMemory,
            self::literalString($context, 'MEMORY'),
            self::literalString($context, 'STDIO')
        );
        $context->builder->call($setString, $ht, self::literalString($context, 'stream_type'), $streamTypeStr);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $modeFromRegistry = $context->builder->call(
            $context->lookupFunction('__phpc_stream_mode'),
            $handle
        );
        $modeMissing = $context->builder->icmp(Builder::INT_EQ, $modeFromRegistry, $nullStr);
        $fallbackModeBb = $fn->appendBasicBlock('stream_meta_mode_fallback');
        $modeDoneBb = $fn->appendBasicBlock('stream_meta_mode_done');
        $context->builder->branchIf($modeMissing, $fallbackModeBb, $modeDoneBb);

        $context->builder->positionAtEnd($fallbackModeBb);
        $fallbackMode = $context->builder->select(
            $isPhpMemory,
            self::literalString($context, 'w+b'),
            self::literalString($context, 'r+b')
        );
        $context->builder->branch($modeDoneBb);

        $context->builder->positionAtEnd($modeDoneBb);
        $modeStr = $context->builder->phi($strPtr);
        $modeStr->addIncoming($modeFromRegistry, $fillBb);
        $modeStr->addIncoming($fallbackMode, $fallbackModeBb);
        $context->builder->call($setString, $ht, self::literalString($context, 'mode'), $modeStr);

        $pathNull = $context->builder->icmp(Builder::INT_EQ, $pathCstr, $nullPtr);
        $uriBb = $fn->appendBasicBlock('stream_meta_uri');
        $uriEmptyBb = $fn->appendBasicBlock('stream_meta_uri_empty');
        $retBb = $fn->appendBasicBlock('stream_meta_ret');
        $context->builder->branchIf($pathNull, $uriEmptyBb, $uriBb);

        $context->builder->positionAtEnd($uriBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $pathCstr);
        $uriStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $pathCstr
        );
        $context->builder->call($setString, $ht, self::literalString($context, 'uri'), $uriStr);
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($uriEmptyBb);
        $context->builder->call(
            $setString,
            $ht,
            self::literalString($context, 'uri'),
            self::literalString($context, '')
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);
    }

    private static function emitSetBlocking(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_blocking_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('stream_blocking_fail');
        $openBb = $fn->appendBasicBlock('stream_blocking_open');
        $context->builder->branchIf($badHandle, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $filenoBb = $fn->appendBasicBlock('stream_blocking_fileno');
        $context->builder->branchIf($notOpen, $failBb, $filenoBb);

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero32);
        $okNoopBb = $fn->appendBasicBlock('stream_blocking_ok_noop');
        $fcntlBb = $fn->appendBasicBlock('stream_blocking_fcntl');
        $context->builder->branchIf($fdBad, $okNoopBb, $fcntlBb);

        $context->builder->positionAtEnd($fcntlBb);
        $flags = $context->builder->call(
            $context->lookupFunction('fcntl'),
            $fd,
            $i32->constInt(self::F_GETFL, false),
            $zero32
        );
        $modeNonZero = $context->builder->icmp(Builder::INT_NE, $mode, $zero64);
        $nonBlockBit = $i32->constInt(self::O_NONBLOCK, false);
        $nonBlockMask = $i32->constInt(0xFFFFF7FF, false);
        $blockingFlags = $context->builder->and($flags, $nonBlockMask);
        $nonBlockingFlags = $context->builder->or($flags, $nonBlockBit);
        $newFlags = $context->builder->select($modeNonZero, $blockingFlags, $nonBlockingFlags);
        $setRc = $context->builder->call(
            $context->lookupFunction('fcntl'),
            $fd,
            $i32->constInt(self::F_SETFL, false),
            $newFlags
        );
        $setOk = $context->builder->icmp(Builder::INT_NE, $setRc, $i32->constInt(-1, true));
        $context->builder->returnValue($context->builder->select($setOk, $one32, $zero32));

        $context->builder->positionAtEnd($okNoopBb);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero32);
    }

    private static function loadPtrSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamMetaJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    /** @return Value i1 */
    private static function hasPrefix(Context $context, Value $path, string $prefix): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i32->constInt(0, false);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $i8p->constNull());
        $rc = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, $prefix),
            $sizeT->constInt(\strlen($prefix), false)
        );
        $matches = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);

        return $context->builder->and(
            $context->builder->not($pathNull),
            $matches
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['fileno', $i32, [$i8p]],
            ['feof', $i32, [$i8p]],
            ['fcntl', $i32, [$i32, $i32, $i32]],
            ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
            ['strlen', $sizeT, [$i8p]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyBool', $voidTy, [$htPtr, $strPtr, $i1]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureExternGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $tableTy = $i8p->arrayType(self::MAX_HANDLES);
        foreach ([self::GLOBAL_HANDLES, self::GLOBAL_PATHS] as $name) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $context->module->addGlobal($tableTy, $name);
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamMetaJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
