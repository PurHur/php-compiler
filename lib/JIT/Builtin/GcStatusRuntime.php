<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM gc_status() hashtable builder (issues #3280, #5109).
 *
 * php-src: ext/standard/php_gc.c — PHP_FUNCTION(gc_status)
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

        GcCollectCyclesRuntime::ensureLinked($context);
        self::ensureHashtableHelpers($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::FN_STATUS, $ft);
        self::implementStatusHt($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementStatusHt(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('gc_status_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setBool = $context->lookupFunction('__hashtable__setStringKeyBool');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $boolKeys = [
            'running' => self::G_RUNNING,
            'protected' => self::G_PROTECTED,
            'full' => self::G_FULL,
        ];
        foreach ($boolKeys as $key => $globalName) {
            $global = $context->module->getNamedGlobal($globalName);
            if (null === $global) {
                throw new \LogicException('GcStatusRuntime: '.$globalName.' missing');
            }
            $loaded = $context->builder->load($context->builder->pointerCast($global, $i32->pointerType(0)));
            $context->builder->call(
                $setBool,
                $ht,
                self::literalKeyString($context, $key),
                $context->builder->icmp(Builder::INT_NE, $loaded, $i32->constInt(0, false))
            );
        }

        $intKeys = ['runs', 'collected', 'threshold', 'buffer_size', 'roots'];
        $globals = [self::G_RUNS, self::G_TOTAL_COLLECTED, null, self::G_BUFFER_SIZE, self::G_ROOT_COUNT];
        foreach ($intKeys as $idx => $key) {
            $globalName = $globals[$idx];
            if (null === $globalName) {
                $value = $i64->constInt(CycleCollector::ROOT_THRESHOLD, false);
            } else {
                $global = $context->module->getNamedGlobal($globalName);
                if (null === $global) {
                    throw new \LogicException('GcStatusRuntime: '.$globalName.' missing');
                }
                $value = $context->builder->sext(
                    $context->builder->load($context->builder->pointerCast($global, $i32->pointerType(0))),
                    $i64
                );
            }
            $context->builder->call(
                $setLong,
                $ht,
                self::literalKeyString($context, $key),
                $value
            );
        }

        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyLong',
            $context->context->functionType($voidTy, false, $htPtr, $strPtr, $i64)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyBool',
            $context->context->functionType($voidTy, false, $htPtr, $strPtr, $context->getTypeFromString('int1'))
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('int8*'))
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::FN_STATUS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::FN_STATUS.' missing after GcStatusRuntime LLVM implement');
        }
        $context->registerFunction(self::FN_STATUS, $fn);
    }
}
