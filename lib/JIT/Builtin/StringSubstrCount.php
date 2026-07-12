<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_substr_count via SubstrCountJitHelper PHP (#14691).
 *
 * Replaces ~186-line LLVM search loop in ext/standard/JitSubstrCount.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(substr_count)
 */
final class StringSubstrCount
{
    private const HELPER_PATH = '/ext/standard/SubstrCountJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\SubstrCountJitHelper::countArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('phpc_substr_count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_substr_count', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        $context->registerFunction(
            'phpc_substr_count',
            $context->module->getNamedFunction('phpc_substr_count')
                ?? throw new \LogicException('phpc_substr_count missing after StringSubstrCount bridge (#14691)')
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = 'phpc_substr_count';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $i64,
            $i32
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('substr_count_bridge_entry');
        $context->builder->positionAtEnd($entry);

        // Z_PARAM_STR null → "" before helper (php-src ext/standard/string.c, #18265).
        $empty = $context->builder->load($context->constantStringFromString(''));
        $hayParam = $fn->getParam(0);
        $needleParam = $fn->getParam(1);
        $hayNull = $context->builder->icmp(Builder::INT_EQ, $hayParam, $strPtr->constNull());
        $needleNull = $context->builder->icmp(Builder::INT_EQ, $needleParam, $strPtr->constNull());
        $hay = $context->builder->select($hayNull, $empty, $hayParam);
        $needle = $context->builder->select($needleNull, $empty, $needleParam);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COUNT_HELPER),
            [
                $hay,
                $needle,
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SubstrCountJitHelper compile (#14691)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SubstrCountJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SubstrCountJitHelper.php parseAndCompile failed (#14691)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#14691)');
            }
        }
    }
}
