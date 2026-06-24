<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gmgetdate via GmgetdateJitHelper PHP (#9181).
 *
 * Replaces gmtime/hashtable LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(gmgetdate)
 */
final class StringGmgetdate
{
    private const HELPER_PATH = '/ext/standard/GmgetdateJitHelper.php';

    private const GMGETDATE_HELPER = 'PHPCompiler\\ext\\standard\\GmgetdateJitHelper::gmgetdate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GMGETDATE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gmgetdate');
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
        self::implementGmgetdateBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGmgetdateBridge(Context $context): void
    {
        $abiName = '__compiler_gmgetdate';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $ft = $context->context->functionType($voidTy, false, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gmg_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('gmg_null_out');
        $bodyBb = $fn->appendBasicBlock('gmg_body');
        $context->builder->positionAtEnd($entry);

        $timestamp = $fn->getParam(0);
        $out = $fn->getParam(1);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GMGETDATE_HELPER),
            [$timestamp]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $failBb = $fn->appendBasicBlock('gmg_fail');
        $storeBb = $fn->appendBasicBlock('gmg_store');
        $context->builder->branchIf($htNull, $failBb, $storeBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($storeBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GmgetdateJitHelper compile (#9181)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GmgetdateJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GmgetdateJitHelper.php parseAndCompile failed (#9181)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9181)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_gmgetdate');
        if (null === $fn) {
            throw new \LogicException('__compiler_gmgetdate missing after StringGmgetdate bridge (#9181)');
        }
        $context->registerFunction('__compiler_gmgetdate', $fn);
    }
}
