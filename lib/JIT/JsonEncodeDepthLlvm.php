<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitJsonEncodeCompileTime;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmJsonFlags;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Module globals for AOT/JIT json_encode() nesting vs $depth (#34544).
 *
 * php-src: ext/json/json_encoder.c — encoder->depth++ / max_depth → PHP_JSON_ERROR_DEPTH
 * With JSON_PARTIAL_OUTPUT_ON_ERROR, depth overflow still encodes (#34947).
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
     * Enter array/object: depth++. Returns i1 true when encoding should continue.
     *
     * Without PARTIAL, over-max leaves cur_depth unchanged and returns false.
     * With PARTIAL, sets JSON_ERROR_DEPTH, still increments, returns true (#34947).
     */
    public static function tryEnter(Context $context, Value $flags): Value
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
        $partial = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and(
                $flags,
                $i64->constInt(VmJsonFlags::PARTIAL_OUTPUT_ON_ERROR, false)
            ),
            $i64->constInt(0, false)
        );

        $tag = (string) (++self::$seq);
        $okBlock = BasicBlockHelper::append($context, 'json_depth_enter_ok_'.$tag);
        $overBlock = BasicBlockHelper::append($context, 'json_depth_enter_over_'.$tag);
        $partialCont = BasicBlockHelper::append($context, 'json_depth_enter_partial_'.$tag);
        $failBlock = BasicBlockHelper::append($context, 'json_depth_enter_fail_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'json_depth_enter_done_'.$tag);
        $context->builder->branchIf($within, $okBlock, $overBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($next, $curG);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($overBlock);
        JitJsonEncodeCompileTime::emitSetLastError($context, VmJson::ERROR_DEPTH);
        $context->builder->branchIf($partial, $partialCont, $failBlock);

        $context->builder->positionAtEnd($partialCont);
        // php-src still increments depth when PARTIAL keeps encoding past max.
        $context->builder->store($next, $curG);
        $partialEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1, 'json_depth_enter_'.$tag);
        $phi->addIncoming($i1->constInt(1, false), $okEnd);
        $phi->addIncoming($i1->constInt(1, false), $partialEnd);
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
