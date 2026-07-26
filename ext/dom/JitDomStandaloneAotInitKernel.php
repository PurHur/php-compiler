<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomStandaloneAotInitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin standalone AOT: compile DomStandaloneAotInit in the main module (#17391, #19487, #20214, #23374).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer DomDocumentMethod #23325).
 * Gate: {@see Context::isThinStandaloneAotMain()} (peer #20200 / #20178 — no NestedJit defer).
 * Housed in ext/dom (not lib/JIT/Builtin) — same kernel-move pattern as #19430 / #19389.
 */
final class JitDomStandaloneAotInitKernel
{
    private const HELPER_PATH = '/ext/dom/DomStandaloneAotInitJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomStandaloneAotInitJitHelper::registerDomExtensionClasses';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function shouldUse(Context $context): bool
    {
        return $context->isThinStandaloneAotMain();
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(DomStandaloneAotInitRuntime::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(DomStandaloneAotInitRuntime::ABI_NAME, $probe);

            return;
        }

        self::ensureMainModuleHelperCompiled($context);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $voidTy = $context->getTypeFromString('void');
        $helperFn = self::lookupHelper($context);
        $ft = $context->context->functionType($voidTy, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(DomStandaloneAotInitRuntime::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('dom_standalone_aot_init_user_script');
        $context->builder->positionAtEnd($entry);
        $context->builder->call($helperFn, $fn->getParam(0));
        $context->builder->returnVoid();
        $context->registerFunction(DomStandaloneAotInitRuntime::ABI_NAME, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function lookupHelper(Context $context): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, self::HELPER, '#17391');
    }

    private static function ensureMainModuleHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23374'
        );
    }
}
