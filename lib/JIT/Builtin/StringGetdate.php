<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getdate via GetdateJitHelper PHP (#9181).
 *
 * Replaces localtime_r/hashtable LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getdate)
 */
final class StringGetdate
{
    private const HELPER_PATH = '/ext/standard/GetdateJitHelper.php';

    private const GETDATE_HELPER = 'PHPCompiler\\ext\\standard\\GetdateJitHelper::getdate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETDATE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_getdate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementGetdateBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetdateBridge(Context $context): void
    {
        $abiName = '__compiler_getdate';
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

        $entry = $fn->appendBasicBlock('gd_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('gd_null_out');
        $bodyBb = $fn->appendBasicBlock('gd_body');
        $context->builder->positionAtEnd($entry);

        $timestamp = $fn->getParam(0);
        $out = $fn->getParam(1);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $ht = $context->builder->call(
            self::helperFunction($context, self::GETDATE_HELPER),
            $timestamp
        );
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $failBb = $fn->appendBasicBlock('gd_fail');
        $storeBb = $fn->appendBasicBlock('gd_store');
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
            throw new \LogicException($logical.' missing after GetdateJitHelper compile (#9181)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GetdateJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GetdateJitHelper.php parseAndCompile failed (#9181)');
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
        $fn = $context->module->getNamedFunction('__compiler_getdate');
        if (null === $fn) {
            throw new \LogicException('__compiler_getdate missing after StringGetdate bridge (#9181)');
        }
        $context->registerFunction('__compiler_getdate', $fn);
    }
}
