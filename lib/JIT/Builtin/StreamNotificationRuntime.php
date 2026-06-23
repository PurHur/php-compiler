<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_notification_callback via StreamNotificationJitHelper PHP (#9478).
 *
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamNotificationJitHelper compile (#9478)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamNotificationJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamNotificationJitHelper.php parseAndCompile failed (#9478)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9478)');
            }
        }
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
