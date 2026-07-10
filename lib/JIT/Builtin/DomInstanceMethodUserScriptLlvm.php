<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: compile DOM instance bridge in the main module (#17391).
 *
 * Split-compilation helper TUs lack per-unit ctor init for ObjectEntry reads (#16075); nested
 * VmDomInstanceInvoke also needs {@see VmActiveContextLlvm} because Superglobals is unset.
 */
final class DomInstanceMethodUserScriptLlvm
{
    private const HELPER_PATH = '/ext/dom/VmDomInstanceInvoke.php';

    /** @var list<string> */
    private const COMPILED_HELPERS = DomInstanceMethodRuntime::COMPILED_HELPER_LOGICALS;

    public static function shouldUse(Context $context): bool
    {
        return UserScriptAotDeferNestedJit::shouldDefer($context);
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'VmDomInstanceInvoke.php');
            if (null === $block) {
                throw new \LogicException('VmDomInstanceInvoke.php parseAndCompile failed (#17391)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for user-script DOM bridge (#17391)');
            }
        }
    }
}
