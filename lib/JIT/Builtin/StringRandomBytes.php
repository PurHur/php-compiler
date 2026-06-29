<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_random_bytes via RandomBytesJitHelper PHP (#9149).
 *
 * Replaces hand-written urandom open/read LLVM; SSOT {@see \PHPCompiler\ext\standard\VmRandomPure}.
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class StringRandomBytes
{
    private const HELPER_PATH = '/ext/standard/RandomBytesJitHelper.php';

    private const RANDOM_BYTES_HELPER = 'PHPCompiler\\ext\\standard\\RandomBytesJitHelper::randomBytes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANDOM_BYTES_HELPER,
    ];

    public static function implement(Context $context): void
    {
        $abiName = '__compiler_random_bytes';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        if (self::shouldUseUserScriptThinStub($context)) {
            self::implementUserScriptThinStub($context, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64)
            );

        $entry = $fn->appendBasicBlock('rb_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::RANDOM_BYTES_HELPER),
            $fn->getParam(0)
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
            throw new \LogicException($logical.' missing after RandomBytesJitHelper compile (#9149)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'RandomBytesJitHelper.php');
            if (null === $block) {
                throw new \LogicException('RandomBytesJitHelper.php parseAndCompile failed (#9149)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9149)');
            }
        }
    }

    private static function shouldUseUserScriptThinStub(Context $context): bool
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return false;
        }
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' !== $userScript && 'true' !== strtolower((string) $userScript)) {
            return false;
        }

        return true;
    }

    private static function implementUserScriptThinStub(Context $context, ?LlvmFunction $probe): void
    {
        $abiName = '__compiler_random_bytes';
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64)
            );
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock('rb_user_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }
}
