<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_notification_callback via StreamNotificationJitHelper PHP (#9478, #25223).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StreamSocketAccept #25183 / StreamPath #25139).
 * Replaces LLVM module global callback slot; SSOT
 * {@see \PHPCompiler\ext\standard\StreamNotificationJitHelper}.
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_notification_callback)
 */
final class StreamNotificationRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamNotificationJitHelper.php';

    private const SLOT_HELPER = 'PHPCompiler\\ext\\standard\\StreamNotificationJitHelper::jitCallbackSlot';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SLOT_HELPER,
    ];

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

        self::ensureJitHelperCompiled($context);

        $voidTy = $context->getTypeFromString('void');
        $valPtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valPtr, $valPtr);
        $fn = $context->module->addFunction('__phpc_stream_notification_callback_set', $ft);
        self::implementSet($context, $fn);

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSet(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('snc_set_entry');
        $context->builder->positionAtEnd($entry);

        $valPtr = $context->getTypeFromString('__value__*');
        $newCb = $fn->getParam(0);
        $outPrev = $fn->getParam(1);

        $slot = $context->builder->call(self::helperFunction($context, self::SLOT_HELPER));
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $slot, $valPtr->constNull());
        $failBb = $fn->appendBasicBlock('snc_set_fail');
        $bodyBb = $fn->appendBasicBlock('snc_set_body');
        $context->builder->branchIf($slotNull, $failBb, $bodyBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $outPrev);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        JitValueBox::copyFromPointer($context, $outPrev, $slot);
        JitValueBox::copyIntoPointer($context, $slot, $newCb);
        $context->builder->returnVoid();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25223');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25223'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FNS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamNotificationRuntime bridge (#9478)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
