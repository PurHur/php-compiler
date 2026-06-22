<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin as JitBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for output_add_rewrite_var / output_reset_rewrite_vars via OutputRewriteVarsJitHelper PHP (#9753).
 *
 * AOT standalone uses LLVM globals (hashtable) — php-in-PHP helper bool-return LLVM is broken (#10525).
 * JIT embed calls compiled {@see OutputRewriteVarsJitHelper} static storage.
 * php-src: ext/standard/url.c — PHP_FUNCTION(output_add_rewrite_var), output_reset_rewrite_vars.
 * VM SSOT: {@see \PHPCompiler\Web\ResponseContext}.
 */
final class RewriteVarsRuntime
{
    public const GLOBAL = 'phpc_rewrite_vars';

    private const HELPER_PATH = '/ext/standard/OutputRewriteVarsJitHelper.php';

    private const ADD_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::add';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::reset';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ADD_HELPER,
        self::RESET_HELPER,
    ];

    private static int $blockSeq = 0;

    public static function ensureLinked(Context $context): void
    {
        if (JitBuiltin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::ensureLinkedGlobals($context);

            return;
        }
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinkedGlobals($context);
    }

    public static function emitAdd(Context $context, Value $nameStr, Value $valueStr): Value
    {
        if (JitBuiltin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::emitAddGlobals($context, $nameStr, $valueStr);
        }
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::ADD_HELPER),
            $nameStr,
            $valueStr
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    public static function emitReset(Context $context): Value
    {
        if (JitBuiltin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::emitResetGlobals($context);
        }
        self::ensureJitHelperCompiled($context);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function ensureLinkedGlobals(Context $context): void
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        if (null === $context->module->getNamedGlobal(self::GLOBAL)) {
            $context->module->addGlobal($htPtrTy, self::GLOBAL)->setInitializer($htPtrTy->constNull());
        }
    }

    private static function emitAddGlobals(Context $context, Value $nameStr, Value $valueStr): Value
    {
        self::ensureLinkedGlobals($context);
        $ht = self::loadTable($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $nameStr,
            $valueStr
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function emitResetGlobals(Context $context): Value
    {
        self::ensureLinkedGlobals($context);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $global = $context->module->getNamedGlobal(self::GLOBAL);
        if (null === $global) {
            throw new \LogicException('RewriteVarsRuntime global missing: '.self::GLOBAL);
        }
        $context->builder->store($htPtrTy->constNull(), $global);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function loadTable(Context $context): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $global = $context->module->getNamedGlobal(self::GLOBAL);
        if (null === $global) {
            self::ensureLinkedGlobals($context);
            $global = $context->module->getNamedGlobal(self::GLOBAL);
        }
        $cur = $context->builder->load($global);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $cur, $htPtrTy->constNull());
        $tag = 'rw'.(string) ++self::$blockSeq;
        $entry = $context->builder->getInsertBlock();
        $init = BasicBlockHelper::append($context, 'rewrite_vars_ht_init_'.$tag);
        $ready = BasicBlockHelper::append($context, 'rewrite_vars_ht_ready_'.$tag);
        $context->builder->branchIf($isNull, $init, $ready);

        $context->builder->positionAtEnd($init);
        $ht = HashTableHelper::alloc($context);
        $context->builder->store($ht, $global);
        $initEnd = $context->builder->getInsertBlock();
        $context->builder->branch($ready);

        $context->builder->positionAtEnd($ready);
        $phi = $context->builder->phi($htPtrTy);
        $phi->addIncoming($ht, $initEnd);
        $phi->addIncoming($cur, $entry);

        return $phi;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after OutputRewriteVarsJitHelper compile (#9753)');
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
        $realPath = \realpath($path) ?: $path;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'OutputRewriteVarsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('OutputRewriteVarsJitHelper.php parseAndCompile failed (#9753)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
            $context->markJitIncludedFileCompiled($realPath);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9753)');
            }
        }
    }
}
