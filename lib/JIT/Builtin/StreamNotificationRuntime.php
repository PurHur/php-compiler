<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM global stream notification callback storage (#6055).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_notification_callback)
 */
final class StreamNotificationRuntime
{
    private const GLOBAL_CALLBACK = 'phpc_stream_notification_callback';

    /** @var list<string> */
    private const RUNTIME_FNS = [
        '__phpc_stream_notification_callback_set',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_notification_callback_set');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobal($context);

        $voidTy = $context->getTypeFromString('void');
        $valPtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valPtr, $valPtr);
        $fn = $context->module->addFunction('__phpc_stream_notification_callback_set', $ft);
        self::implementSet($context, $fn);

        self::registerLinkedRuntime($context);
    }

    private static function implementSet(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('snc_set_entry');
        $context->builder->positionAtEnd($entry);

        $valPtr = $context->getTypeFromString('__value__*');
        $newCb = $fn->getParam(0);
        $outPrev = $fn->getParam(1);

        $global = $context->module->getNamedGlobal(self::GLOBAL_CALLBACK);
        if (null === $global) {
            throw new \LogicException('Missing global '.self::GLOBAL_CALLBACK);
        }
        $globalPtr = $context->builder->pointerCast($global, $valPtr->pointerType(0));
        $oldCb = $context->builder->load($globalPtr);

        $hasOldBb = $fn->appendBasicBlock('snc_set_has_old');
        $noOldBb = $fn->appendBasicBlock('snc_set_no_old');
        $afterPrevBb = $fn->appendBasicBlock('snc_set_after_prev');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $oldCb, $valPtr->constNull()),
            $hasOldBb,
            $noOldBb
        );

        $context->builder->positionAtEnd($noOldBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $outPrev);
        $context->builder->branch($afterPrevBb);

        $context->builder->positionAtEnd($hasOldBb);
        JitValueBox::copyFromPointer($context, $outPrev, $oldCb);
        $context->builder->branch($afterPrevBb);

        $context->builder->positionAtEnd($afterPrevBb);
        $context->builder->store($newCb, $globalPtr);
        $context->builder->returnVoid();
    }

    private static function ensureGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::GLOBAL_CALLBACK)) {
            return;
        }
        $valPtr = $context->getTypeFromString('__value__*');
        $global = $context->module->addGlobal($valPtr, self::GLOBAL_CALLBACK);
        $global->setInitializer($valPtr->constNull());
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FNS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
