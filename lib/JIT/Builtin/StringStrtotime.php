<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_strtotime via StrtotimeJitHelper PHP (#10742).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmDateTimeNative::strtotime()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime)
 */
final class StringStrtotime
{
    private const HELPER_PATH = '/ext/standard/StrtotimeJitHelper.php';

    private const STRTOTIME_HELPER = 'PHPCompiler\\ext\\standard\\StrtotimeJitHelper::strtotimeArgv';

    private const LAST_TIMESTAMP = 'PHPCompiler\\ext\\standard\\StrtotimeJitHelper::lastTimestamp';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRTOTIME_HELPER,
        self::LAST_TIMESTAMP,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_strtotime');
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
        self::implementStrtotimeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStrtotimeBridge(Context $context): void
    {
        $abiName = '__compiler_strtotime';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $strPtr, $i64, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('st_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('st_null_out');
        $bodyBb = $fn->appendBasicBlock('st_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(3);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STRTOTIME_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $tagI32 = $context->builder->trunc(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $tag, $i32),
            $i32
        );
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $tagI32,
            $i32->constInt(\PHPCompiler\ext\standard\StrtotimeJitHelper::TAG_FALSE, false)
        );
        $falseBb = BasicBlockHelper::append($context, 'st_false');
        $okBb = BasicBlockHelper::append($context, 'st_ok');
        $doneBb = BasicBlockHelper::append($context, 'st_done');
        $context->builder->branchIf($isFalse, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $timestamp = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_TIMESTAMP),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $timestamp, $i64)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StrtotimeJitHelper compile (#10742)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StrtotimeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StrtotimeJitHelper.php parseAndCompile failed (#10742)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10742)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_strtotime');
        if (null === $fn) {
            throw new \LogicException('__compiler_strtotime missing after StringStrtotime bridge (#10742)');
        }
        $context->registerFunction('__compiler_strtotime', $fn);
    }
}
