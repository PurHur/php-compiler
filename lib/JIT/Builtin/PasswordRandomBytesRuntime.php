<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Production random_bytes bridge for password_hash nested JIT (#9275).
 *
 * Avoids user-script __compiler_random_bytes null stub during AOT password crypto.
 * SSOT: {@see \PHPCompiler\ext\standard\RandomBytesJitHelper}
 */
final class PasswordRandomBytesRuntime
{
    private const ABI = '__compiler_password_random_bytes';

    private const HELPER_PATH = '/ext/standard/RandomBytesJitHelper.php';

    private const RANDOM_BYTES_HELPER = 'PHPCompiler\\ext\\standard\\RandomBytesJitHelper::randomBytes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANDOM_BYTES_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            self::restoreInsertBlock($context, $savedBlock);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $i64)
            );

        $entry = $fn->appendBasicBlock('pw_rb_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::RANDOM_BYTES_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI, $fn);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after RandomBytesJitHelper compile (#9275)');
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
                throw new \LogicException('RandomBytesJitHelper.php parseAndCompile failed (#9275)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for password random bytes (#9275)');
            }
        }
    }

    private static function restoreInsertBlock(Context $context, mixed $savedBlock): void
    {
        if (null !== $savedBlock) {
            try {
                $context->builder->positionAtEnd($savedBlock);
            } catch (\Throwable) {
                $context->builder->clearInsertionPosition();
            }

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
