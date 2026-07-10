<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT: module-global active VM Context pointer (#17391).
 *
 * MCJIT embed sets Superglobals::$activeContext; user-script AOT does not — RuntimeInitVmContext
 * stores the allocated Context in {@see GLOBAL_NAME} for JIT/AOT helpers (ext/dom, stdlib JitHelpers).
 */
final class VmActiveContextLlvm
{
    public const GLOBAL_NAME = 'sg_vm_context';

    private const ABI = '__phpc_vm_active_context';

    public static function ensureGlobal(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $existing = $context->module->getNamedGlobal(self::GLOBAL_NAME);
        if (null === $existing) {
            $global = $context->module->addGlobal($objPtr, self::GLOBAL_NAME);
            $global->setInitializer($objPtr->constNull());
        }
    }

    public static function storeContext(Context $context, \PHPLLVM\Value $ctxObj): void
    {
        self::ensureGlobal($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $global = $context->module->getNamedGlobal(self::GLOBAL_NAME);
        if (null === $global) {
            throw new \LogicException(self::GLOBAL_NAME.' missing before storeContext (#17391)');
        }
        $context->builder->store($ctxObj, $global);
    }

    public static function ensureAbi(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureGlobal($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($objPtr, false)
            );
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $fn);
            self::restoreInsertBlock($context, $savedBlock);

            return;
        }

        $entry = $fn->appendBasicBlock('vm_active_context_load');
        $context->builder->positionAtEnd($entry);
        $global = $context->module->getNamedGlobal(self::GLOBAL_NAME);
        if (null === $global) {
            throw new \LogicException(self::GLOBAL_NAME.' missing for '.self::ABI.' (#17391)');
        }
        $loaded = $context->builder->load($global);
        $context->builder->returnValue($loaded);
        $context->registerFunction(self::ABI, $fn);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    public static function lookupAbi(Context $context): LlvmFunction
    {
        self::ensureAbi($context);

        return $context->lookupFunction(self::ABI);
    }
}
