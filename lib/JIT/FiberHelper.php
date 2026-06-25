<?php

declare(strict_types=1);

/**
 * JIT fiber lowering — thin trampoline to {@see FiberHelperLlvm} + {@see \PHPCompiler\VM\VmFiberValue} (#10079).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * MCJIT fiber registration + trampolines (issue #3130, #4019, #10079).
 *
 * Resume-ip LLVM lives in {@see FiberHelperLlvm}; value semantics in {@see \PHPCompiler\VM\VmFiberValue}.
 * php-src: Zend/zend_fibers.c.
 */
final class FiberHelper
{
    public const TARGET_PROPERTY = '__fiber_resume';

    public const STATE_PROPERTY = '__fiber_state';

    public static function registerJitMethods(Context $context): void
    {
        $context->functionProxies['fiber::__construct'] = new Call\FiberConstruct();
        $context->functionProxies['fiber::start'] = new Call\FiberStart();
        $context->functionProxies['fiber::resume'] = new Call\FiberResume();
        $context->functionProxies['fiber::throw'] = new Call\FiberThrow();
        $context->functionProxies['fiber::suspend'] = new Call\FiberSuspendStatic();
        $context->functionProxies['fiber::getreturn'] = new Call\FiberGetReturn();
    }

    public static function blockContainsFiberSuspend(?Block $block): bool
    {
        return FiberHelperLlvm::blockContainsFiberSuspend($block);
    }

    public static function ensureTypes(Context $context): void
    {
        FiberHelperLlvm::ensureTypes($context);
    }

    public static function isFiberSuspendInit(Block $block, OpCode $op): bool
    {
        return FiberHelperLlvm::isFiberSuspendInit($block, $op);
    }

    /** @return list<array{op: OpCode, index: int, block: Block}> */
    public static function collectSuspendPoints(Block $block): array
    {
        return FiberHelperLlvm::collectSuspendPoints($block);
    }

    public static function compileResumeFunction(
        \PHPCompiler\JIT $jit,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        return FiberHelperLlvm::compileResumeFunction($jit, $internalName, $block, $logicalName);
    }

    public static function allocateFiberCallbackObject(Context $context, string $resumeInternalName): Variable
    {
        return FiberHelperLlvm::allocateFiberCallbackObject($context, $resumeInternalName);
    }

    public static function initFiberState(Context $context): Value
    {
        return FiberHelperLlvm::initFiberState($context);
    }

    public static function loadResumeNameFromObject(Context $context, Variable $obj): string
    {
        return FiberHelperLlvm::loadResumeNameFromObject($context, $obj);
    }

    public static function storeStateOnFiberObject(Context $context, Value $fiberObj, Value $statePtr): void
    {
        FiberHelperLlvm::storeStateOnFiberObject($context, $fiberObj, $statePtr);
    }

    public static function loadStateFromFiberObject(Context $context, Variable $fiberVar): Value
    {
        return FiberHelperLlvm::loadStateFromFiberObject($context, $fiberVar);
    }

    public static function storeResumeNameOnFiber(Context $context, Value $fiberObj, string $resumeName): void
    {
        FiberHelperLlvm::storeResumeNameOnFiber($context, $fiberObj, $resumeName);
    }

    public static function assignValueField(Context $context, Value $destField, Variable $src, ?Operand $srcOp = null): void
    {
        FiberHelperLlvm::assignValueField($context, $destField, $src, $srcOp);
    }

    public static function resolveResumeLc(Context $context, Variable $fiberVar): string
    {
        return FiberHelperLlvm::resolveResumeLc($context, $fiberVar);
    }

    public static function runResumeAndBoxResult(Context $context, string $resumeLc, Value $statePtr): Variable
    {
        return FiberHelperLlvm::runResumeAndBoxResult($context, $resumeLc, $statePtr);
    }
}
