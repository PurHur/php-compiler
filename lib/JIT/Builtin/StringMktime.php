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
 * JIT/AOT link for __compiler_mktime via MktimeJitHelper PHP (#9132).
 *
 * Replaces libc struct tm / mktime LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(mktime)
 */
final class StringMktime
{
    private const HELPER_PATH = '/ext/standard/MktimeJitHelper.php';

    private const MKTIME_HELPER = 'PHPCompiler\\ext\\standard\\MktimeJitHelper::mktimeArgv';

    private const LAST_TIMESTAMP = 'PHPCompiler\\ext\\standard\\MktimeJitHelper::lastTimestamp';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MKTIME_HELPER,
        self::LAST_TIMESTAMP,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_mktime');
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
        self::implementMktimeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementMktimeBridge(Context $context): void
    {
        $abiName = '__compiler_mktime';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $i64, $i64, $i64, $i64, $i64, $i64, $i1, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('mkt_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('mkt_null_out');
        $bodyBb = $fn->appendBasicBlock('mkt_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(7);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MKTIME_HELPER),
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
                $fn->getParam(5),
                $fn->getParam(6),
            ]
        );
        $tagI32 = $context->builder->trunc(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $tag, $i32),
            $i32
        );
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $tagI32,
            $i32->constInt(\PHPCompiler\ext\standard\MktimeJitHelper::TAG_FALSE, false)
        );
        $falseBb = BasicBlockHelper::append($context, 'mkt_false');
        $okBb = BasicBlockHelper::append($context, 'mkt_ok');
        $doneBb = BasicBlockHelper::append($context, 'mkt_done');
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
            throw new \LogicException($logical.' missing after MktimeJitHelper compile (#9132)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'MktimeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('MktimeJitHelper.php parseAndCompile failed (#9132)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9132)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_mktime');
        if (null === $fn) {
            throw new \LogicException('__compiler_mktime missing after StringMktime bridge (#9132)');
        }
        $context->registerFunction('__compiler_mktime', $fn);
    }
}
