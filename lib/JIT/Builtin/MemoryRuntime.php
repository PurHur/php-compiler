<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT memory_get_usage()/memory_get_peak_usage() — PHP LLVM lowering (#3134, #5377).
 *
 * Replaces {@see lib/AOT/runtime/phpc_memory.c}; semantics match {@see \PHPCompiler\ext\standard\VmMemory}.
 */
final class MemoryRuntime
{
    public const GLOBAL_PEAK_EMALLOC = '__phpc_memory_peak_emalloc';

    public const GLOBAL_PEAK_REAL = '__phpc_memory_peak_real';

    public const READ_RSS = '__phpc_memory_read_rss_bytes';

    /** @var Value|null */
    public static $peakEmallocGlobal = null;

    /** @var Value|null */
    public static $peakRealGlobal = null;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function peakGlobal(Context $context, bool $realUsage): Value
    {
        self::ensureLinked($context);
        $global = $realUsage ? self::$peakRealGlobal : self::$peakEmallocGlobal;
        if (null === $global) {
            throw new \LogicException('MemoryRuntime peak global missing after ensureLinked');
        }

        return $global;
    }

    public static function readRssBytes(Context $context): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::READ_RSS));
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::READ_RSS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::$peakEmallocGlobal = $context->module->getNamedGlobal(self::GLOBAL_PEAK_EMALLOC);
            self::$peakRealGlobal = $context->module->getNamedGlobal(self::GLOBAL_PEAK_REAL);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_PEAK_EMALLOC)) {
            self::$peakEmallocGlobal = $context->module->addGlobal($i64, self::GLOBAL_PEAK_EMALLOC);
            self::$peakEmallocGlobal->setInitializer($i64->constInt(0, false));
        } else {
            self::$peakEmallocGlobal = $context->module->getNamedGlobal(self::GLOBAL_PEAK_EMALLOC);
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_PEAK_REAL)) {
            self::$peakRealGlobal = $context->module->addGlobal($i64, self::GLOBAL_PEAK_REAL);
            self::$peakRealGlobal->setInitializer($i64->constInt(0, false));
        } else {
            self::$peakRealGlobal = $context->module->getNamedGlobal(self::GLOBAL_PEAK_REAL);
        }

        self::ensureLibcForStatm($context);
        self::emitReadRssBytes($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function captureInsertBlock(Context $context): ?Value
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?Value $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitReadRssBytes(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);

        $fn = $context->module->addFunction(
            self::READ_RSS,
            $context->context->functionType($i64, false)
        );
        $context->registerFunction(self::READ_RSS, $fn);

        $entry = $fn->appendBasicBlock('mem_rss_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $bufLen = 128;
        $buf = $context->builder->alloca($i8, $i64->constInt($bufLen, false), 'mem_statm_buf');
        $path = self::cstring($context, '/proc/self/statm');

        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $path,
            $i32->constInt(0, false),
            $i32->constInt(0, false)
        );
        $openFail = $context->builder->icmp(Builder::INT_SLT, $fd, $zeroI32);
        $failBlock = $fn->appendBasicBlock('mem_rss_fail');
        $openOk = $fn->appendBasicBlock('mem_rss_open_ok');
        $context->builder->branchIf($openFail, $failBlock, $openOk);

        $context->builder->positionAtEnd($openOk);
        $nRead = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $context->builder->pointerCast($buf, $i8p),
            $context->builder->truncOrBitCast($i64->constInt($bufLen - 1, false), $sizeT)
        );
        $context->builder->call($context->lookupFunction('close'), $fd);

        $readFail = $context->builder->icmp(Builder::INT_SLE, $nRead, $i64->constInt(0, false));
        $parseBlock = $fn->appendBasicBlock('mem_rss_parse');
        $context->builder->branchIf($readFail, $failBlock, $parseBlock);

        $context->builder->positionAtEnd($parseBlock);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $nRead));

        $endPtrSlot = $context->builder->alloca($i8p, 1, 'mem_rss_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $context->builder->pointerCast($buf, $i8p),
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $rssStart = $context->builder->load($endPtrSlot);
        $rssPages = $context->builder->call(
            $context->lookupFunction('strtol'),
            $rssStart,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $rssPages64 = $context->builder->truncOrBitCast($rssPages, $i64);
        $bytes = $context->builder->mul($rssPages64, $i64->constInt(4096, false));
        $context->builder->returnValue($bytes);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->clearInsertionPosition();
    }

    private static function cstring(Context $context, string $text): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $len = strlen($text) + 1;
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType($len));
        $ptr = $context->builder->pointerCast($buf, $i8p);
        for ($i = 0; $i < strlen($text); ++$i) {
            $context->builder->store(
                $i8->constInt(ord($text[$i]), false),
                $context->builder->inBoundsGEP($ptr, $context->getTypeFromString('int64')->constInt($i, false))
            );
        }
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($ptr, $context->getTypeFromString('int64')->constInt(strlen($text), false))
        );

        return $ptr;
    }

    private static function ensureLibcForStatm(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            'read',
            $context->context->functionType($sizeT, false, $i32, $i8p, $sizeT)
        );
        self::ensureExternal(
            $context,
            'close',
            $context->context->functionType($i32, false, $i32)
        );
        self::ensureExternal(
            $context,
            'strtol',
            $context->context->functionType($i64, false, $i8p, $i8pp, $i32)
        );
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
}
