<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Zend-visible object handles for spl_object_id / spl_object_hash on thin AOT (#28661, #24292).
 *
 * php-src: ext/spl/php_spl.c — spl_object_id uses sequential handles, not pointer addresses.
 */
final class ObjectHandleRuntime
{
    public const NEXT_GLOBAL = '__phpc_next_object_handle';

    public const BASELINE_GLOBAL = '__phpc_object_handle_baseline';

    public static function registerDeclarations(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $context->module->getNamedGlobal(self::NEXT_GLOBAL)) {
            $next = $context->module->addGlobal($i64, self::NEXT_GLOBAL);
            $next->setInitializer($i64->constInt(1, false));
        }
        if (null === $context->module->getNamedGlobal(self::BASELINE_GLOBAL)) {
            $baseline = $context->module->addGlobal($i64, self::BASELINE_GLOBAL);
            $baseline->setInitializer($i64->constInt(0, false));
        }
    }

    /** Stamp {@see Object_::allocate()} results with the next monotonic handle. */
    public static function emitAssignHandle(Context $context, Value $obj): void
    {
        self::registerDeclarations($context);
        $map = $context->structFieldMap['__object__'];
        if (!isset($map['user_handle'])) {
            throw new \LogicException('__object__ missing user_handle field (#28661)');
        }
        $i64 = $context->getTypeFromString('int64');
        $nextGlobal = $context->module->getNamedGlobal(self::NEXT_GLOBAL);
        $handle = $context->builder->load($nextGlobal);
        $context->builder->store(
            $handle,
            $context->builder->structGep($obj, $map['user_handle'])
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($handle, $i64->constInt(1, false)),
            $nextGlobal
        );
    }

    /**
     * Return handle - baseline when above baseline (peer {@see CycleCollector::userVisibleObjectHandle}).
     */
    public static function emitUserVisibleHandle(Context $context, Value $obj): Value
    {
        self::registerDeclarations($context);
        $map = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->load(
            $context->builder->structGep($obj, $map['user_handle'])
        );
        $baseline = $context->builder->load(
            $context->module->getNamedGlobal(self::BASELINE_GLOBAL)
        );
        $above = $context->builder->icmp(Builder::INT_SGT, $raw, $baseline);

        return $context->builder->select(
            $above,
            $context->builder->subNoSignedWrap($raw, $baseline),
            $raw
        );
    }

    /** Snap after __init__ so bootstrap objects do not consume user-visible ids (#24292). */
    public static function emitSnapBaselineForStandaloneMain(Context $context): void
    {
        self::registerDeclarations($context);
        $i64 = $context->getTypeFromString('int64');
        $nextGlobal = $context->module->getNamedGlobal(self::NEXT_GLOBAL);
        $baselineGlobal = $context->module->getNamedGlobal(self::BASELINE_GLOBAL);
        $next = $context->builder->load($nextGlobal);
        $context->builder->store(
            $context->builder->subNoSignedWrap($next, $i64->constInt(1, false)),
            $baselineGlobal
        );
    }
}
