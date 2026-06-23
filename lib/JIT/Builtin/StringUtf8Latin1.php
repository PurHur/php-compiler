<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for utf8_encode()/utf8_decode() via Utf8Latin1JitHelper PHP (#9912).
 *
 * Replaces StringUtf8Latin1Jit LLVM; SSOT {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/utf8.c — php_utf8_encode, php_utf8_decode
 */
final class StringUtf8Latin1
{
    private const HELPER_PATH = '/ext/standard/Utf8Latin1JitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\Utf8Latin1JitHelper::encode';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\Utf8Latin1JitHelper::decode';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
        self::DECODE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_utf8_encode',
        '__compiler_utf8_decode',
    ];

    public static function ensureLinked(Context $context): void
    {
        $resume = $context->builder->getInsertBlock();
        self::implement($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_utf8_encode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context, '__compiler_utf8_encode', self::ENCODE_HELPER);
        self::implementBridge($context, '__compiler_utf8_decode', self::DECODE_HELPER);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('utf8_latin1_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Utf8Latin1JitHelper compile (#9912)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Utf8Latin1JitHelper.php');
            if (null === $block) {
                throw new \LogicException('Utf8Latin1JitHelper.php parseAndCompile failed (#9912)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9912)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUtf8Latin1 bridge (#9912)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
