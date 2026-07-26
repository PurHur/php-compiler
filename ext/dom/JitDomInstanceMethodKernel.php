<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin standalone AOT: compile DOM instance bridge in the main module (#17391, #19487, #20214, #23361).
 *
 * Split-compilation helper TUs lack per-unit ctor init for ObjectEntry reads (#16075); nested
 * VmDomInstanceInvoke also needs {@see VmActiveContextLlvm} because Superglobals is unset.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer DomDocumentMethod #23325).
 * Gate: {@see Context::isThinStandaloneAotMain()} (peer #20200 / #20178 — no NestedJit defer).
 * Housed in ext/dom (not lib/JIT/Builtin) — same kernel-move pattern as #19430 / #19389.
 */
final class JitDomInstanceMethodKernel
{
    private const HELPER_PATH = '/ext/dom/VmDomInstanceInvoke.php';

    /** @var list<string> */
    private const COMPILED_HELPERS = DomInstanceMethodRuntime::COMPILED_HELPER_LOGICALS;

    public static function shouldUse(Context $context): bool
    {
        return $context->isThinStandaloneAotMain();
    }

    public static function ensureBridge(Context $context, int $extraArgCount): void
    {
        DomInstanceMethodRuntime::assertValidArity($extraArgCount);
        $abi = DomInstanceMethodRuntime::abiForArity($extraArgCount);
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $paramTypes = [$valuePtr, $strPtr];
        for ($i = 0; $i < $extraArgCount; ++$i) {
            $paramTypes[] = $valuePtr;
        }

        $helperFn = self::lookupHelper($context, DomInstanceMethodRuntime::invokeLogicalForArity($extraArgCount));
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = $fn->appendBasicBlock('dom_instance_method_user_script_'.$extraArgCount);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $result = $context->builder->call($helperFn, ...$args);
        $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $valuePtr);
        $context->builder->returnValue($ret);
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function lookupHelper(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#17391');
    }

    private static function ensureNestedHelperProxies(Context $context): void
    {
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toobject');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        foreach (['string', 'int', 'null', 'object', 'bool'] as $writeMethod) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $writeMethod);
        }
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
    }

    private static function ensureMainModuleHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23361'
        );
    }
}
