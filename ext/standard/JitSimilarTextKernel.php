<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM Oliver similar_text kernel (mirrors VmString::similar_text / former phpc_similar_text.c).
 *
 * Quarantined from {@see \PHPCompiler\JIT\Builtin\StringSimilarText} (#30810).
 * Thin AOT NestedJIT of {@see SimilarTextJitHelper} segfaults (recursive by-ref Oliver —
 * peer NaturalCompare #26975 / #30088). Keep the algorithm as LLVM here; Builtin stays a
 * thin orchestrator. No 255-byte cap (#18543).
 *
 * php-src: ext/standard/string.c — php_similar_str / php_similar_char / PHP_FUNCTION(similar_text)
 */
final class JitSimilarTextKernel
{
    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('phpc_similar_text');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_similar_text', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_similar_str', self::emitSimilarStr(...));
        self::implementIfMissing($context, '__phpc_similar_char', self::emitSimilarChar(...));
        self::implementIfMissing($context, 'phpc_similar_text', self::emitSimilarText(...));

        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $ptr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $context->getTypeFromString('size_t*');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->context->voidType();

        return match ($name) {
            '__phpc_similar_str' => $context->module->addFunction(
                $name,
                $context->context->functionType(
                    $void,
                    false,
                    $ptr,
                    $sizeT,
                    $ptr,
                    $sizeT,
                    $sizeTp,
                    $sizeTp,
                    $sizeTp,
                    $sizeTp
                )
            ),
            '__phpc_similar_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($sizeT, false, $ptr, $sizeT, $ptr, $sizeT)
            ),
            'phpc_similar_text' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $ptr, $ptr)
            ),
            default => throw new \LogicException('Unknown similar_text JIT helper: '.$name),
        };
    }

    private static function emitSimilarText(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $s1 = $fn->getParam(0);
        $s2 = $fn->getParam(1);
        $ptr = $context->getTypeFromString('char*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $nullPtr = $ptr->constNull();
        $strlenFn = $context->lookupFunction('strlen');
        $zeroI64 = $i64->constInt(0, false);

        // Null → "" (php-src / former phpc_similar_text.c); never strlen(NULL).
        $emptySlot = $context->builder->alloca($i8, 1);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);
        $emptyAsChar = $context->builder->pointerCast($emptyPtr, $ptr);

        $s1Use = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $s1, $nullPtr),
            $emptyAsChar,
            $s1
        );
        $s2Use = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $s2, $nullPtr),
            $emptyAsChar,
            $s2
        );

        $len1 = $context->builder->zExt($context->builder->call($strlenFn, $s1Use), $sizeT);
        $len2 = $context->builder->zExt($context->builder->call($strlenFn, $s2Use), $sizeT);

        $bothZero = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $len1, $sizeT->constInt(0, false)),
            $context->builder->icmp(Builder::INT_EQ, $len2, $sizeT->constInt(0, false))
        );

        $zeroRetBb = $fn->appendBasicBlock('sim_zero_ret');
        $computeBb = $fn->appendBasicBlock('sim_compute');
        $doneBb = $fn->appendBasicBlock('sim_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);

        $context->builder->branchIf($bothZero, $zeroRetBb, $computeBb);

        $context->builder->positionAtEnd($zeroRetBb);
        $context->builder->store($zeroI64, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($computeBb);
        $sum = $context->builder->call(
            $context->lookupFunction('__phpc_similar_char'),
            $s1Use,
            $len1,
            $s2Use,
            $len2
        );
        $context->builder->store($context->builder->zExt($sum, $i64), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->load($resultSlot));
    }

    private static function emitSimilarChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $txt1 = $fn->getParam(0);
        $len1 = $fn->getParam(1);
        $txt2 = $fn->getParam(2);
        $len2 = $fn->getParam(3);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $pos1Slot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $pos2Slot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $maxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $countSlot = BasicBlockHelper::entryAlloca($context, $sizeT);

        $context->builder->call(
            $context->lookupFunction('__phpc_similar_str'),
            $txt1,
            $len1,
            $txt2,
            $len2,
            $pos1Slot,
            $pos2Slot,
            $maxSlot,
            $countSlot
        );

        $max = $context->builder->load($maxSlot);
        $sumSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($max, $sumSlot);

        $hasMatch = $context->builder->icmp(Builder::INT_UGT, $max, $zero);
        $recurseBb = $fn->appendBasicBlock('sim_char_recurse');
        $doneBb = $fn->appendBasicBlock('sim_char_done');
        $context->builder->branchIf($hasMatch, $recurseBb, $doneBb);

        $context->builder->positionAtEnd($recurseBb);
        $pos1 = $context->builder->load($pos1Slot);
        $pos2 = $context->builder->load($pos2Slot);
        $count = $context->builder->load($countSlot);
        $sum = $context->builder->load($sumSlot);

        $leftRecBb = $fn->appendBasicBlock('sim_char_left_rec');
        $afterLeftBb = $fn->appendBasicBlock('sim_char_after_left');
        $needLeft = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGT, $pos1, $zero),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_UGT, $pos2, $zero),
                $context->builder->icmp(Builder::INT_UGT, $count, $one)
            )
        );
        $context->builder->branchIf($needLeft, $leftRecBb, $afterLeftBb);

        $context->builder->positionAtEnd($leftRecBb);
        $leftSum = $context->builder->call(
            $context->lookupFunction('__phpc_similar_char'),
            $txt1,
            $pos1,
            $txt2,
            $pos2
        );
        $context->builder->store($context->builder->add($sum, $leftSum), $sumSlot);
        $context->builder->branch($afterLeftBb);

        $context->builder->positionAtEnd($afterLeftBb);
        $sum = $context->builder->load($sumSlot);
        $pos1 = $context->builder->load($pos1Slot);
        $pos2 = $context->builder->load($pos2Slot);
        $max = $context->builder->load($maxSlot);

        $rightStart1 = $context->builder->add($pos1, $max);
        $rightStart2 = $context->builder->add($pos2, $max);
        $rightLen1 = $context->builder->sub($len1, $rightStart1);
        $rightLen2 = $context->builder->sub($len2, $rightStart2);
        $needRight = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $rightStart1, $len1),
            $context->builder->icmp(Builder::INT_ULT, $rightStart2, $len2)
        );

        $rightRecBb = $fn->appendBasicBlock('sim_char_right_rec');
        $context->builder->branchIf($needRight, $rightRecBb, $doneBb);

        $context->builder->positionAtEnd($rightRecBb);
        $rightPtr1 = $context->builder->inBoundsGEP($txt1, $rightStart1);
        $rightPtr2 = $context->builder->inBoundsGEP($txt2, $rightStart2);
        $rightSum = $context->builder->call(
            $context->lookupFunction('__phpc_similar_char'),
            $rightPtr1,
            $rightLen1,
            $rightPtr2,
            $rightLen2
        );
        $context->builder->store($context->builder->add($sum, $rightSum), $sumSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->load($sumSlot));
    }

    private static function emitSimilarStr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $txt1 = $fn->getParam(0);
        $len1 = $fn->getParam(1);
        $txt2 = $fn->getParam(2);
        $len2 = $fn->getParam(3);
        $pos1Out = $fn->getParam(4);
        $pos2Out = $fn->getParam(5);
        $maxOut = $fn->getParam(6);
        $countOut = $fn->getParam(7);

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $context->builder->store($zero, $maxOut);
        $context->builder->store($zero, $countOut);
        $context->builder->store($zero, $pos1Out);
        $context->builder->store($zero, $pos2Out);

        $pSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $qSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $pSlot);

        $loopPBb = $fn->appendBasicBlock('sim_str_p_head');
        $loopPBody = $fn->appendBasicBlock('sim_str_p_body');
        $loopPNext = $fn->appendBasicBlock('sim_str_p_next');
        $exitBb = $fn->appendBasicBlock('sim_str_exit');
        $context->builder->branch($loopPBb);

        $context->builder->positionAtEnd($loopPBb);
        $p = $context->builder->load($pSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $p, $len1),
            $loopPBody,
            $exitBb
        );

        $context->builder->positionAtEnd($loopPBody);
        $context->builder->store($zero, $qSlot);
        $loopQBb = $fn->appendBasicBlock('sim_str_q_head');
        $loopQBody = $fn->appendBasicBlock('sim_str_q_body');
        $loopQNext = $fn->appendBasicBlock('sim_str_q_next');
        $loopQDone = $fn->appendBasicBlock('sim_str_q_done');
        $context->builder->branch($loopQBb);

        $context->builder->positionAtEnd($loopQBb);
        $q = $context->builder->load($qSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $q, $len2),
            $loopQBody,
            $loopQDone
        );

        $context->builder->positionAtEnd($loopQBody);
        $p = $context->builder->load($pSlot);
        $q = $context->builder->load($qSlot);
        $lSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $lSlot);

        $matchHead = $fn->appendBasicBlock('sim_str_match_head');
        $matchBody = $fn->appendBasicBlock('sim_str_match_body');
        $matchDone = $fn->appendBasicBlock('sim_str_match_done');
        $context->builder->branch($matchHead);

        $context->builder->positionAtEnd($matchHead);
        $l = $context->builder->load($lSlot);
        $pPlusL = $context->builder->add($p, $l);
        $qPlusL = $context->builder->add($q, $l);
        $canMatch = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $pPlusL, $len1),
            $context->builder->icmp(Builder::INT_ULT, $qPlusL, $len2)
        );
        $context->builder->branchIf($canMatch, $matchBody, $matchDone);

        $context->builder->positionAtEnd($matchBody);
        $l = $context->builder->load($lSlot);
        $b1 = $context->builder->load($context->builder->inBoundsGEP($txt1, $context->builder->add($p, $l)));
        $b2 = $context->builder->load($context->builder->inBoundsGEP($txt2, $context->builder->add($q, $l)));
        $bytesEq = $context->builder->icmp(Builder::INT_EQ, $b1, $b2);
        $incBb = $fn->appendBasicBlock('sim_str_match_inc');
        $context->builder->branchIf($bytesEq, $incBb, $matchDone);

        $context->builder->positionAtEnd($incBb);
        $context->builder->store($context->builder->add($l, $one), $lSlot);
        $context->builder->branch($matchHead);

        $context->builder->positionAtEnd($matchDone);
        $l = $context->builder->load($lSlot);
        $curMax = $context->builder->load($maxOut);
        $isBetter = $context->builder->icmp(Builder::INT_UGT, $l, $curMax);
        $updateBb = $fn->appendBasicBlock('sim_str_update');
        $context->builder->branchIf($isBetter, $updateBb, $loopQNext);

        $context->builder->positionAtEnd($updateBb);
        $pUpd = $context->builder->load($pSlot);
        $qUpd = $context->builder->load($qSlot);
        $context->builder->store($l, $maxOut);
        $context->builder->store($context->builder->add($context->builder->load($countOut), $one), $countOut);
        $context->builder->store($pUpd, $pos1Out);
        $context->builder->store($qUpd, $pos2Out);
        $context->builder->branch($loopQNext);

        $context->builder->positionAtEnd($loopQNext);
        $qNext = $context->builder->load($qSlot);
        $context->builder->store($context->builder->add($qNext, $one), $qSlot);
        $context->builder->branch($loopQBb);

        $context->builder->positionAtEnd($loopQDone);
        $context->builder->branch($loopPNext);

        $context->builder->positionAtEnd($loopPNext);
        $pNext = $context->builder->load($pSlot);
        $context->builder->store($context->builder->add($pNext, $one), $pSlot);
        $context->builder->branch($loopPBb);

        $context->builder->positionAtEnd($exitBb);
        $context->builder->returnVoid();
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
