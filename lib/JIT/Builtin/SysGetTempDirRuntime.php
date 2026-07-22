<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sys_get_temp_dir via SysGetTempDirJitHelper PHP (#9585).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GraphemeStrSplitRuntime #22147).
 * Replaces {@see StringFsDirJit::emitSysGetTempDir} LLVM getenv/realpath walk.
 * SSOT: {@see \PHPCompiler\ext\standard\VmSysGetTempDirNative}
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class SysGetTempDirRuntime
{
    private const HELPER_PATH = '/ext/standard/SysGetTempDirJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\SysGetTempDirJitHelper::resolve';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_sys_get_temp_dir',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_sys_get_temp_dir');
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
        self::implementIfMissing($context, '__compiler_sys_get_temp_dir', self::implementResolveBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $strPtr = $context->getTypeFromString('__string__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false)
        );
    }

    private static function implementResolveBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sys_get_temp_dir_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::RESOLVE_HELPER)
        );
        $context->builder->returnValue($result);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SysGetTempDirJitHelper compile (#9585)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22187'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SysGetTempDirRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
