<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_bzcompress/__compiler_bzdecompress via Bz2JitHelper PHP (#8868).
 *
 * Replaces {@see StringBz2Jit} LLVM (~284 LOC libbz2). SSOT: {@see \PHPCompiler\ext\bz2\VmBz2Native}.
 * php-src: ext/bz2/bz2.c
 */
final class Bz2Runtime
{
    private const HELPER_PATH = '/ext/bz2/Bz2JitHelper.php';

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\bz2\\Bz2JitHelper::compressArgv';

    private const DECOMPRESS_HELPER = 'PHPCompiler\\ext\\bz2\\Bz2JitHelper::decompressArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPRESS_HELPER,
        self::DECOMPRESS_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_bzcompress',
        '__compiler_bzdecompress',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            Bz2StandaloneLlvm::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_bzcompress');
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
        self::implementIfMissing($context, '__compiler_bzcompress', self::COMPRESS_HELPER, 3);
        self::implementIfMissing($context, '__compiler_bzdecompress', self::DECOMPRESS_HELPER, 2);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementIfMissing(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $params = match ($abiName) {
            '__compiler_bzcompress' => [$strPtr, $i64, $i64],
            '__compiler_bzdecompress' => [$strPtr, $i64],
            default => throw new \LogicException('unknown bz2 ABI: '.$abiName),
        };
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($strPtr, false, ...$params)
        );

        $entry = $fn->appendBasicBlock('bz2_bridge_entry');
        $failBb = $fn->appendBasicBlock('bz2_bridge_fail');
        $okBb = $fn->appendBasicBlock('bz2_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $args = [$fn->getParam(0)];
        if ($paramCount >= 2) {
            $i32 = $context->getTypeFromString('int32');
            $args[] = $context->builder->trunc($fn->getParam(1), $i32);
        }
        if ($paramCount >= 3) {
            $i32 = $context->getTypeFromString('int32');
            $args[] = $context->builder->trunc($fn->getParam(2), $i32);
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            $args
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
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
            throw new \LogicException($logical.' missing after Bz2JitHelper compile (#8868)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Bz2JitHelper.php');
            if (null === $block) {
                throw new \LogicException('Bz2JitHelper.php parseAndCompile failed (#8868)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT bz2 (#8868)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after Bz2Runtime bridge (#8868)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
