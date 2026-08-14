<?php

declare(strict_types=1);

/**
 * JIT fiber lowering — thin trampoline to {@see FiberHelperLlvm} + {@see \PHPCompiler\VM\VmFiberValue} (#10079).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
        $context->functionProxies['fiber::getcurrent'] = new Call\FiberGetCurrent();
        $context->functionProxies['fiber::getreturn'] = new Call\FiberGetReturn();
        $context->functionProxies['fiber::isterminated'] = new Call\FiberIsTerminated();
        $context->functionProxies['fiber::isstarted'] = new Call\FiberIsStarted();
        $context->functionProxies['fiber::issuspended'] = new Call\FiberIsSuspended();
        $context->functionProxies['fiber::isrunning'] = new Call\FiberIsRunning();
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

    /**
     * @param 'started'|'suspended'|'terminated'|'running' $which
     */
    public static function loadStatusBool(Context $context, Variable $fiberVar, string $which): Variable
    {
        return FiberHelperLlvm::loadStatusBool($context, $fiberVar, $which);
    }

    /**
     * Instance-method user argc (excludes $this). php-src ZEND_NUM_ARGS (#30906).
     *
     * @param Variable[] $args
     */
    public static function emitExactInstanceUserArgc(
        Context $context,
        array $args,
        string $function,
        int $expected
    ): bool {
        return self::emitUserArgcExact($context, max(0, \count($args) - 1), $function, $expected);
    }

    /**
     * Instance-method user argc (excludes $this). php-src ZEND_NUM_ARGS (#30906).
     *
     * @param Variable[] $args
     */
    public static function emitAtMostInstanceUserArgc(
        Context $context,
        array $args,
        string $function,
        int $maximum
    ): bool {
        return self::emitUserArgcAtMost($context, max(0, \count($args) - 1), $function, $maximum);
    }

    /**
     * Static-method argc (no $this). php-src ZEND_NUM_ARGS (#30906).
     *
     * @param Variable[] $args
     */
    public static function emitExactStaticArgc(
        Context $context,
        array $args,
        string $function,
        int $expected
    ): bool {
        return self::emitUserArgcExact($context, \count($args), $function, $expected);
    }

    /**
     * Static-method argc (no $this). php-src ZEND_NUM_ARGS (#30906).
     *
     * @param Variable[] $args
     */
    public static function emitAtMostStaticArgc(
        Context $context,
        array $args,
        string $function,
        int $maximum
    ): bool {
        return self::emitUserArgcAtMost($context, \count($args), $function, $maximum);
    }

    public static function dummyNullValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function dummyNativeFalse(Context $context): Value
    {
        return $context->getTypeFromString('int1')->constInt(0, false);
    }

    private static function emitUserArgcExact(
        Context $context,
        int $given,
        string $function,
        int $expected
    ): bool {
        if ($given === $expected) {
            return true;
        }
        ExceptionBridge::emitArgumentCountErrorAndAbort(
            $context,
            VmClassMethod::exactUserArgCountMessage($function, $expected, $given)
        );
        BasicBlockHelper::ensureOpenInsertBlock(
            $context,
            strtolower(str_replace('::', '_', $function)).'_argc_cont'
        );

        return false;
    }

    private static function emitUserArgcAtMost(
        Context $context,
        int $given,
        string $function,
        int $maximum
    ): bool {
        if ($given <= $maximum) {
            return true;
        }
        ExceptionBridge::emitArgumentCountErrorAndAbort(
            $context,
            VmClassMethod::atMostUserArgCountMessage($function, $maximum, $given)
        );
        BasicBlockHelper::ensureOpenInsertBlock(
            $context,
            strtolower(str_replace('::', '_', $function)).'_argc_cont'
        );

        return false;
    }
}
