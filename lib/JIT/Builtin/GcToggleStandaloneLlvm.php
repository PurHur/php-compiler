<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * AOT standalone phpc_gc_enable/disable/is_enabled until GcToggleJitHelper static link is reliable (#9577).
 *
 * JIT embed uses {@see GcToggleRuntime} PHP bridges; standalone keeps a thin LLVM global mirror of
 * {@see \PHPCompiler\ext\standard\GcToggleJitHelper} until native link can rely on compiled helpers.
 */
final class GcToggleStandaloneLlvm
{
    private const G_ENABLED = 'phpc_gc_enabled';

    public static function implement(Context $context): void
    {
        self::ensureGlobal($context);
        self::implementEnable($context);
        self::implementDisable($context);
        self::implementIsEnabled($context);
    }

    private static function implementEnable(Context $context): void
    {
        self::implementStore($context, 'phpc_gc_enable', 1);
    }

    private static function implementDisable(Context $context): void
    {
        self::implementStore($context, 'phpc_gc_disable', 0);
    }

    private static function implementStore(Context $context, string $name, int $value): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($name, $fn);

            return;
        }

        $entry = $fn->appendBasicBlock($name.'_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->store(
            $i32->constInt($value, false),
            self::globalPtr($context, $i32)
        );
        $context->builder->returnVoid();
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementIsEnabled(Context $context): void
    {
        $name = 'phpc_gc_is_enabled';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($name, $fn);

            return;
        }

        $entry = $fn->appendBasicBlock('gc_is_enabled_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $loaded = $context->builder->load(self::globalPtr($context, $i32));
        $isOn = $context->builder->icmp(Builder::INT_NE, $loaded, $i32->constInt(0, false));
        $context->builder->returnValue($context->builder->zext($isOn, $i32));
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureGlobal(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        if (null === $context->module->getNamedGlobal(self::G_ENABLED)) {
            $g = $context->module->addGlobal($i32, self::G_ENABLED);
            $g->setInitializer($i32->constInt(1, false));
        }
    }

    private static function globalPtr(Context $context, $i32): Value
    {
        self::ensureGlobal($context);
        $global = $context->module->getNamedGlobal(self::G_ENABLED);
        if (null === $global) {
            throw new \LogicException(self::G_ENABLED.' missing after GcToggleStandaloneLlvm (#9577)');
        }

        return $context->builder->pointerCast($global, $i32->pointerType(0));
    }
}
