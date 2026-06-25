<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for proc_close/status/terminate via ProcessOpenJitHelper PHP (#9408).
 *
 * Standalone AOT keeps LLVM in {@see ProcessOpenStandaloneLlvm}.
 * SSOT: {@see \PHPCompiler\ext\standard\ProcessOpenJitHelper}
 * php-src: ext/standard/proc_open.c
 */
final class ProcessOpenEmbedBridge
{
    private const HELPER_PATH = '/ext/standard/ProcessSlotJitHelper.php';

    private const OPEN_HELPER_PATH = '/ext/standard/ProcessOpenJitHelper.php';

    private const IS_PROCESS = 'PHPCompiler\\ext\\standard\\ProcessOpenJitHelper::isProcessResourceArgv';

    private const PROC_CLOSE = 'PHPCompiler\\ext\\standard\\ProcessOpenJitHelper::procCloseArgv';

    private const PROC_GET_STATUS = 'PHPCompiler\\ext\\standard\\ProcessOpenJitHelper::procGetStatusArgv';

    private const PROC_TERMINATE = 'PHPCompiler\\ext\\standard\\ProcessOpenJitHelper::procTerminateArgv';

    private const REGISTER_SLOT = 'PHPCompiler\\ext\\standard\\ProcessOpenJitHelper::registerSlotArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_PROCESS,
        self::PROC_CLOSE,
        self::PROC_GET_STATUS,
        self::PROC_TERMINATE,
        self::REGISTER_SLOT,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_process_resource',
        '__compiler_proc_close',
        '__compiler_proc_get_status',
        '__compiler_proc_open',
        '__compiler_proc_terminate',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_proc_close');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementI32Bridge($context, '__compiler_is_process_resource', self::IS_PROCESS, 1);
        self::implementI32Bridge($context, '__compiler_proc_close', self::PROC_CLOSE, 1);
        self::implementProcGetStatusBridge($context);
        self::implementProcTerminateBridge($context);
        ProcessOpenStandaloneLlvm::implementProcOpenOnly($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function registerSlotHelperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return self::helperFunction($context, self::REGISTER_SLOT);
    }

    private static function implementI32Bridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $argCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $params = [$i64];
        if ('__compiler_proc_terminate' === $abiName) {
            $params[] = $i32;
        }
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($i32, false, ...$params)
        );

        $entry = $fn->appendBasicBlock('process_open_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $args = [$context->builder->trunc($fn->getParam(0), $i32)];
        if (2 === $argCount) {
            $args[] = $fn->getParam(1);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            $args
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementProcGetStatusBridge(Context $context): void
    {
        $abiName = '__compiler_proc_get_status';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($htPtr, false, $i64)
        );

        $entry = $fn->appendBasicBlock('proc_get_status_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PROC_GET_STATUS),
            [$context->builder->trunc($fn->getParam(0), $i32)]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failBb = $fn->appendBasicBlock('proc_get_status_bridge_fail');
        $okBb = $fn->appendBasicBlock('proc_get_status_bridge_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementProcTerminateBridge(Context $context): void
    {
        self::implementI32Bridge($context, '__compiler_proc_terminate', self::PROC_TERMINATE, 2);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ProcessOpenJitHelper compile (#9408)');
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
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            foreach ([self::HELPER_PATH, self::OPEN_HELPER_PATH] as $rel) {
                $path = $root.$rel;
                $base = \basename($path);
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), $base);
                if (null === $block) {
                    throw new \LogicException($base.' parseAndCompile failed (#9408)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT process-open helpers (#9408)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ProcessOpenEmbedBridge (#9408)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
