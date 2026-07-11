<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;

/**
 * User-script standalone AOT: compile DOMDocument::loadHTML helper in the main module (#17954).
 */
final class DomDocumentMethodUserScriptLlvm
{
    public static function shouldUse(Context $context): bool
    {
        return UserScriptAotDeferNestedJit::shouldDefer($context);
    }

    public static function ensureLoadHTMLBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomLoadHTMLRuntime::ABI_NAME,
            'dom_load_html_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadHTMLJitHelper::loadHTMLArgv',
            '/ext/dom/DomLoadHTMLJitHelper.php'
        );
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        \PHPLLVM\Type $returnType,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#17954');
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = $fn->appendBasicBlock($entryBlock);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $result = $context->builder->call($helperFn, ...$args);
        $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
        $context->builder->returnValue($ret);
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function ensureMainModuleHelperCompiled(
        Context $context,
        string $relativePath,
        array $compiledHelpers
    ): void {
        $missing = false;
        foreach ($compiledHelpers as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).$relativePath;
        NestedVmActiveContextLlvm::ensureMethod($context);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), \basename($path));
            if (null === $block) {
                throw new \LogicException(\basename($path).' parseAndCompile failed (#17954)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach ($compiledHelpers as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for user-script DOM loadHTML bridge (#17954)');
            }
        }
    }
}
