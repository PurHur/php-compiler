<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for gc_status() (#9150, #26472, #26943).
 *
 * VM SSOT stays in {@see \PHPCompiler\ext\standard\VmGcStatus} → GcStatusJitHelper.
 * Thin AOT NestedJIT of Variable+HashTable::add miscompiled to setStringKeyObject (#26943);
 * this bridge materializes the table via __hashtable__setStringKey* (peer LastErrorRuntime).
 * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(gc_status)
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: "Current basic block has no parent function", peer #26884).
 */
final class GcStatusRuntime
{
    public const G_RUNS = 'phpc_gc_runs';

    public const G_TOTAL_COLLECTED = 'phpc_gc_total_collected';

    public const G_ROOT_COUNT = 'phpc_gc_count';

    public const G_RUNNING = 'phpc_gc_running';

    public const G_PROTECTED = 'phpc_gc_protected';

    public const G_FULL = 'phpc_gc_full';

    public const G_BUFFER_SIZE = 'phpc_gc_buffer_size';

    private const FN_STATUS = '__phpc_gc_status_ht';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FN_STATUS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit
        // (gc_status thin AOT: "Current basic block has no parent function", #26943 / peer #26884).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        GcCollectCyclesRuntime::ensureLinked($context);
        self::ensureHashtableHelpers($context);
        self::registerDeclarations($context);
        self::implementStatusBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerDeclarations(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::declareIfMissing(
            $context,
            self::FN_STATUS,
            $context->context->functionType($htPtr, false)
        );
    }

    private static function declareIfMissing(Context $context, string $name, $ft): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);
    }

    private static function implementStatusBridge(Context $context): void
    {
        $abiName = self::FN_STATUS;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false)
            );

        $entry = $fn->appendBasicBlock('gc_status_bridge_entry');
        $context->builder->positionAtEnd($entry);

        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            self::emitPhp84StatusBridge($context);
        } else {
            self::emitLegacyStatusBridge($context);
        }

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitPhp84StatusBridge(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $double = $context->getTypeFromString('double');

        $running = self::loadGlobalBool($context, self::G_RUNNING, $i32, $i1);
        $protected = self::loadGlobalBool($context, self::G_PROTECTED, $i32, $i1);
        $full = self::loadGlobalBool($context, self::G_FULL, $i32, $i1);
        $runs = self::loadGlobalInt($context, self::G_RUNS, $i32, $i64);
        $collected = self::loadGlobalInt($context, self::G_TOTAL_COLLECTED, $i32, $i64);
        $threshold = $i64->constInt(CycleCollector::ROOT_THRESHOLD, false);
        $bufferSize = self::loadGlobalInt($context, self::G_BUFFER_SIZE, $i32, $i64);
        $roots = self::loadGlobalInt($context, self::G_ROOT_COUNT, $i32, $i64);
        // Timing floats: keys required (#20627); cold AOT/JIT starts at 0.0 (VM tracks via CycleCollector).
        $zero = $double->constReal(0.0);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setBool($context, $ht, 'running', $running);
        self::setBool($context, $ht, 'protected', $protected);
        self::setBool($context, $ht, 'full', $full);
        self::setLong($context, $ht, 'runs', $runs);
        self::setLong($context, $ht, 'collected', $collected);
        self::setLong($context, $ht, 'threshold', $threshold);
        self::setLong($context, $ht, 'buffer_size', $bufferSize);
        self::setLong($context, $ht, 'roots', $roots);
        self::setDouble($context, $ht, 'application_time', $zero);
        self::setDouble($context, $ht, 'collector_time', $zero);
        self::setDouble($context, $ht, 'destructor_time', $zero);
        self::setDouble($context, $ht, 'free_time', $zero);
        $context->builder->returnValue($ht);
    }

    private static function emitLegacyStatusBridge(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $runs = self::loadGlobalInt($context, self::G_RUNS, $i32, $i64);
        $collected = self::loadGlobalInt($context, self::G_TOTAL_COLLECTED, $i32, $i64);
        $roots = self::loadGlobalInt($context, self::G_ROOT_COUNT, $i32, $i64);
        $threshold = $i64->constInt(CycleCollector::ROOT_THRESHOLD, false);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setLong($context, $ht, 'runs', $runs);
        self::setLong($context, $ht, 'collected', $collected);
        self::setLong($context, $ht, 'threshold', $threshold);
        self::setLong($context, $ht, 'roots', $roots);
        $context->builder->returnValue($ht);
    }

    private static function setLong(Context $context, Value $ht, string $key, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            self::literalKeyString($context, $key),
            $value
        );
    }

    private static function setBool(Context $context, Value $ht, string $key, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            self::literalKeyString($context, $key),
            $value
        );
    }

    private static function setDouble(Context $context, Value $ht, string $key, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyDouble'),
            $ht,
            self::literalKeyString($context, $key),
            $value
        );
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $charPtr)
        );
    }

    private static function loadGlobalInt(Context $context, string $globalName, $i32, $i64): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GcStatusRuntime: '.$globalName.' missing');
        }
        $loaded = $context->builder->load($context->builder->pointerCast($global, $i32->pointerType(0)));

        return $context->builder->sext($loaded, $i64);
    }

    private static function loadGlobalBool(Context $context, string $globalName, $i32, $i1): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GcStatusRuntime: '.$globalName.' missing');
        }
        $loaded = $context->builder->load($context->builder->pointerCast($global, $i32->pointerType(0)));

        return $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $loaded,
            $i32->constInt(0, false)
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $double = $context->getTypeFromString('double');
        $void = $context->getTypeFromString('void');

        self::declareIfMissing($context, '__hashtable__alloc', $context->context->functionType($htPtr, false));
        self::declareIfMissing(
            $context,
            '__hashtable__setStringKeyLong',
            $context->context->functionType($void, false, $htPtr, $strPtr, $i64)
        );
        self::declareIfMissing(
            $context,
            '__hashtable__setStringKeyBool',
            $context->context->functionType($void, false, $htPtr, $strPtr, $i1)
        );
        self::declareIfMissing(
            $context,
            '__hashtable__setStringKeyDouble',
            $context->context->functionType($void, false, $htPtr, $strPtr, $double)
        );
        self::declareIfMissing(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('char*'))
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::FN_STATUS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::FN_STATUS.' missing after GcStatusRuntime bridge (#9150)');
        }
        $context->registerFunction(self::FN_STATUS, $fn);
    }
}
