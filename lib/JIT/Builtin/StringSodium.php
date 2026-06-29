<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sodium_secretbox* via SodiumJitHelper PHP (#13078).
 *
 * php-src: ext/sodium/libsodium.c
 */
final class StringSodium
{
    private const HELPER_PATH = '/ext/sodium/SodiumJitHelper.php';

    private const SECRETBOX_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::secretbox';

    private const SECRETBOX_OPEN_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::secretboxOpen';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SECRETBOX_HELPER,
        self::SECRETBOX_OPEN_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementBridge($context, '__compiler_sodium_secretbox', self::SECRETBOX_HELPER);
        self::implementBridge($context, '__compiler_sodium_secretbox_open', self::SECRETBOX_OPEN_HELPER);
    }

    private static function implementBridge(Context $context, string $abiName, string $helper): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helper),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SodiumJitHelper compile (#13078)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SodiumJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SodiumJitHelper.php parseAndCompile failed (#13078)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#13078)');
            }
        }
    }
}
