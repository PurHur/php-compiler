<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT process helpers via ProcessJitHelper PHP (#9337, #12950).
 *
 * JIT embed and AOT standalone compile thin LLVM bridges; SSOT {@see \PHPCompiler\ext\standard\ProcessJitHelper}.
 * User-script exec capture via {@see ProcessExecCaptureNativeJitHelper} + {@see JitVmHelperLink} (#19006).
 * Deferred shell_exec via {@see JitVmHelperLink} + {@see ProcessJitHelper} (#19086).
 * php-src: ext/standard/exec.c — shell_exec, escapeshellarg, escapeshellcmd
 */
final class ProcessRuntime
{
    private const HELPER_PATH = '/ext/standard/ProcessJitHelper.php';

    private const EXEC_CAPTURE_HELPER_PATH = '/ext/standard/ProcessExecCaptureNativeJitHelper.php';

    private const PHPC_RUN_COMMAND_HELPER_PATH = '/ext/standard/ProcessPhpcRunCommandJitHelper.php';

    private const SHELL_EXEC = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::shellExecArgv';

    private const ESCAPESHELLARG = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::escapeshellargArgv';

    private const ESCAPESHELLCMD = 'PHPCompiler\\ext\\standard\\ProcessJitHelper::escapeshellcmdArgv';

    private const PHPC_RUN_COMMAND = 'PHPCompiler\\ext\\standard\\ProcessPhpcRunCommandJitHelper::phpcRunCommandArgv';

    private const PROCESS_EXEC_CAPTURE = 'PHPCompiler\\ext\\standard\\ProcessExecCaptureNativeJitHelper::processExecCaptureArgv';

    /** @var list<string> */
    private const SHELL_COMPILED_HELPERS = [
        self::SHELL_EXEC,
        self::ESCAPESHELLARG,
        self::ESCAPESHELLCMD,
    ];

    /** @var list<string> */
    private const SHELL_RUNTIME_FUNCTIONS = [
        '__compiler_shell_exec',
        '__compiler_escapeshellarg',
        '__compiler_escapeshellcmd',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_shell_exec');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerShellRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureShellHelperCompiled($context);
        self::implementNullableStringBridge($context, '__compiler_shell_exec', self::SHELL_EXEC);
        self::implementStringBridge($context, '__compiler_escapeshellarg', self::ESCAPESHELLARG);
        self::implementStringBridge($context, '__compiler_escapeshellcmd', self::ESCAPESHELLCMD);
        self::registerShellRuntime($context);

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

    public static function ensureExecCaptureLinked(Context $context): void
    {
        self::ensureLinked($context);
        $probe = $context->module->getNamedFunction('__compiler_process_exec_capture');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_process_exec_capture', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureNativeHtInternalProxies($context);
        self::ensureExecCaptureHelperCompiled($context);
        self::implementExecCaptureNativeBridge($context);
        $fn = $context->module->getNamedFunction('__compiler_process_exec_capture');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__compiler_process_exec_capture missing after ProcessRuntime bridge (#9337)');
        }
        $context->registerFunction('__compiler_process_exec_capture', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensurePhpcRunCommandLinked(Context $context): void
    {
        self::ensureLinked($context);
        $probe = $context->module->getNamedFunction('__compiler_phpc_run_command');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_phpc_run_command', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensurePhpcRunCommandHelperCompiled($context);
        self::implementPhpcRunCommandBridge($context);
        $fn = $context->module->getNamedFunction('__compiler_phpc_run_command');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__compiler_phpc_run_command missing after ProcessRuntime bridge (#9337)');
        }
        $context->registerFunction('__compiler_phpc_run_command', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
            [$fn->getParam(0), $fn->getParam(1)]
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
        if (\strtolower(self::PHPC_RUN_COMMAND) === \strtolower($logical)) {
            self::ensurePhpcRunCommandHelperCompiled($context);
        } elseif (\strtolower(self::PROCESS_EXEC_CAPTURE) === \strtolower($logical)) {
            self::ensureExecCaptureHelperCompiled($context);
        } else {
            self::ensureShellHelperCompiled($context);
        }
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ProcessJitHelper compile (#9337)');
        }

        return $fn;
    }

    private static function ensureShellHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::SHELL_COMPILED_HELPERS,
            '#19086'
        );
    }

    private static function ensureExecCaptureHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::EXEC_CAPTURE_HELPER_PATH,
            [self::PROCESS_EXEC_CAPTURE],
            '#19006'
        );
    }

    private static function implementExecCaptureNativeBridge(Context $context): void
    {
        $abiName = '__compiler_process_exec_capture';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($htPtr, false, $strPtr));

        $entry = $fn->appendBasicBlock('process_exec_capture_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PROCESS_EXEC_CAPTURE),
            [$fn->getParam(0)]
        );
        $failed = $context->builder->icmp(
            Builder::INT_EQ,
            $resultRaw,
            $i64->constInt(0, false)
        );
        $failBb = $fn->appendBasicBlock('process_exec_capture_fail');
        $okBb = $fn->appendBasicBlock('process_exec_capture_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $result = JitNestedHelperCoerce::i64ToTypedPtr($context, $resultRaw, $htPtr);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    /** Register phpc_native_ht_* Internal JIT handlers before nested exec-capture compile (#10492). */
    private static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_alloc(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_long(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_hashtable_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    private static function ensurePhpcRunCommandHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::PHPC_RUN_COMMAND_HELPER_PATH,
            [self::PHPC_RUN_COMMAND],
            '#21857'
        );
    }

    private static function registerShellRuntime(Context $context): void
    {
        foreach (self::SHELL_RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ProcessRuntime bridge (#9337)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
