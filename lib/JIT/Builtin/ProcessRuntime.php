<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT process helpers — embed PHP bridge + standalone LLVM quarantine (#9337).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\ProcessJitHelper}
 * php-src: ext/standard/exec.c — shell_exec, escapeshellarg, escapeshellcmd
 */
final class ProcessRuntime
{
    private const HELPER_PATH = '/ext/standard/ProcessJitHelper.php';

    private const SHELL_EXEC = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::shellExecArgv';

    private const ESCAPESHELLARG = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::escapeshellargArgv';

    private const ESCAPESHELLCMD = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::escapeshellcmdArgv';

    private const PHPC_RUN_COMMAND = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::phpcRunCommandArgv';

    private const PROCESS_EXEC_CAPTURE = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::processExecCaptureArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SHELL_EXEC,
        self::ESCAPESHELLARG,
        self::ESCAPESHELLCMD,
        self::PHPC_RUN_COMMAND,
        self::PROCESS_EXEC_CAPTURE,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_shell_exec',
        '__compiler_escapeshellarg',
        '__compiler_escapeshellcmd',
        '__compiler_phpc_run_command',
        '__compiler_process_exec_capture',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ProcessStandaloneLlvm::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_shell_exec');
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
        self::implementNullableStringBridge($context, '__compiler_shell_exec', self::SHELL_EXEC);
        self::implementStringBridge($context, '__compiler_escapeshellarg', self::ESCAPESHELLARG);
        self::implementStringBridge($context, '__compiler_escapeshellcmd', self::ESCAPESHELLCMD);
        self::implementPhpcRunCommandBridge($context);
        self::implementHashtableBridge($context, '__compiler_process_exec_capture', self::PROCESS_EXEC_CAPTURE);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStringBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($strPtr, false, $strPtr));

        $entry = $fn->appendBasicBlock('process_str_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$fn->getParam(0)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementNullableStringBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($strPtr, false, $strPtr));

        $entry = $fn->appendBasicBlock('process_nullable_str_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$fn->getParam(0)]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $resultRaw);
        $failBb = $fn->appendBasicBlock('process_nullable_str_fail');
        $okBb = $fn->appendBasicBlock('process_nullable_str_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementHashtableBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($htPtr, false, $strPtr));

        $entry = $fn->appendBasicBlock('process_ht_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$fn->getParam(0)]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $resultRaw);
        $failBb = $fn->appendBasicBlock('process_ht_fail');
        $okBb = $fn->appendBasicBlock('process_ht_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $result = JitNestedHelperCoerce::coerceToHashtablePtr($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPhpcRunCommandBridge(Context $context): void
    {
        $abiName = '__compiler_phpc_run_command';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($htPtr, false, $strPtr, $htPtr));

        $entry = $fn->appendBasicBlock('phpc_run_command_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PHPC_RUN_COMMAND),
            [$fn->getParam(0), $env]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $resultRaw);
        $failBb = $fn->appendBasicBlock('phpc_run_command_fail');
        $okBb = $fn->appendBasicBlock('phpc_run_command_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $result = JitNestedHelperCoerce::coerceToHashtablePtr($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ProcessJitHelper compile (#9337)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ProcessJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ProcessJitHelper.php parseAndCompile failed (#9337)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT process helpers (#9337)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ProcessRuntime bridge (#9337)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
