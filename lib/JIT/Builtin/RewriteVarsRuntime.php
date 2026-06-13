<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Request-scoped mod_rewrite var table for JIT/AOT (issue #6031).
 *
 * php-src: ext/standard/url.c — PHP_FUNCTION(output_add_rewrite_var), output_reset_rewrite_vars.
 * VM SSOT: {@see \PHPCompiler\Web\ResponseContext}.
 */
final class RewriteVarsRuntime
{
    public const GLOBAL = 'phpc_rewrite_vars';

    private static int $blockSeq = 0;

    public static function ensureLinked(Context $context): void
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        if (null === $context->module->getNamedGlobal(self::GLOBAL)) {
            $context->module->addGlobal($htPtrTy, self::GLOBAL)->setInitializer($htPtrTy->constNull());
        }
    }

    public static function emitAdd(Context $context, Value $nameStr, Value $valueStr): Value
    {
        self::ensureLinked($context);
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

    public static function emitReset(Context $context): Value
    {
        self::ensureLinked($context);
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
            self::ensureLinked($context);
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
}
