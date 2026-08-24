<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitJsonEncodeCompileTime;
use PHPCompiler\ext\standard\VmJson;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Module globals for AOT/JIT json_encode() nesting vs $depth (#34544).
 *
 * php-src: ext/json/json_encoder.c — encoder->depth++ / max_depth → PHP_JSON_ERROR_DEPTH
 */
final class JsonEncodeDepthLlvm
{
    public const MAX_GLOBAL = '__phpc_json_encode_max_depth';

    public const CUR_GLOBAL = '__phpc_json_encode_cur_depth';

    private static int $seq = 0;

    public static function ensureGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $context->module->getNamedGlobal(self::MAX_GLOBAL)) {
            $max = $context->module->addGlobal($i64, self::MAX_GLOBAL);
            $max->setInitializer($i64->constInt(512, false));
        }
        if (null === $context->module->getNamedGlobal(self::CUR_GLOBAL)) {
            $cur = $context->module->addGlobal($i64, self::CUR_GLOBAL);
            $cur->setInitializer($i64->constInt(0, false));
        }
    }

    /** Reset nesting and publish max depth before a top-level encode (#34544). */
    public static function resetForEncode(Context $context, Value $maxDepth): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store($maxDepth, $context->module->getNamedGlobal(self::MAX_GLOBAL));
        $context->builder->store(
            $i64->constInt(0, false),
            $context->module->getNamedGlobal(self::CUR_GLOBAL)
        );
        // php-src ext/json/json.c — php_json_encode clears JSON_G(error_code) on entry.
        JitJsonEncodeCompileTime::emitSetLastError($context, 0);
    }

    /**
     * Enter array/object: depth++. Returns i1 true when still within max_depth.
     * On failure sets JSON_ERROR_DEPTH and leaves cur_depth unchanged.
     */
    public static function tryEnter(Context $context): Value
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $curG = $context->module->getNamedGlobal(self::CUR_GLOBAL);
        $maxG = $context->module->getNamedGlobal(self::MAX_GLOBAL);
        $cur = $context->builder->load($curG);
        $next = $context->builder->addNoSignedWrap($cur, $i64->constInt(1, false));
        $max = $context->builder->load($maxG);
        $within = $context->builder->icmp(Builder::INT_SLE, $next, $max);

        $tag = (string) (++self::$seq);
        $okBlock = BasicBlockHelper::append($context, 'json_depth_enter_ok_'.$tag);
        $failBlock = BasicBlockHelper::append($context, 'json_depth_enter_fail_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'json_depth_enter_done_'.$tag);
        $context->builder->branchIf($within, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($next, $curG);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        JitJsonEncodeCompileTime::emitSetLastError($context, VmJson::ERROR_DEPTH);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1, 'json_depth_enter_'.$tag);
        $phi->addIncoming($i1->constInt(1, false), $okEnd);
        $phi->addIncoming($i1->constInt(0, false), $failEnd);

        return $phi;
    }

    /** Leave array/object: depth-- (#34544). */
    public static function leave(Context $context): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $curG = $context->module->getNamedGlobal(self::CUR_GLOBAL);
        $cur = $context->builder->load($curG);
        $context->builder->store(
            $context->builder->sub($cur, $i64->constInt(1, false)),
            $curG
        );
    }
}
