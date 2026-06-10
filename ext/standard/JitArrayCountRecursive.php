<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Function_ as LlvmFunction;
use PHPLLVM\Value;

/**
 * LLVM lowering for count($array, COUNT_RECURSIVE) (#3511, #4584).
 *
 * php-src: ext/standard/array.c — php_count_recursive
 */
final class JitArrayCountRecursive
{
    private static int $blockSerial = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implementIfMissing($context, '__hashtable__countRecursive', self::emitCountRecursive(...));
    }

    public static function invoke(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__countRecursive'),
            $ht
        );

        return $context->builder->zExt($count, $context->getTypeFromString('int64'));
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
            $htPtr = $context->getTypeFromString('__hashtable__*');
            $sizeT = $context->getTypeFromString('size_t');
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($sizeT, false, $htPtr)
            );
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitCountRecursive(Context $context, LlvmFunction $fn): void
    {
        $block = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);

        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $valueMap = $context->structFieldMap['__value__'];
        $htType = $i8->constInt(Variable::TYPE_HASHTABLE, false);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $totalSlot = $context->builder->alloca($sizeT, 1, 'count_rec_total');
        $base = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $context->builder->store($base, $totalSlot);

        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $idxSlot = $context->builder->alloca($sizeT, 1, 'count_rec_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = $fn->appendBasicBlock('count_rec_packed_head');
        $packedBody = $fn->appendBasicBlock('count_rec_packed_body');
        $packedCheck = $fn->appendBasicBlock('count_rec_packed_check');
        $packedRecurse = $fn->appendBasicBlock('count_rec_packed_recurse');
        $packedSkip = $fn->appendBasicBlock('count_rec_packed_skip');
        $packedAdvance = $fn->appendBasicBlock('count_rec_packed_advance');
        $packedDone = $fn->appendBasicBlock('count_rec_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedCheck, $packedSkip);

        $context->builder->positionAtEnd($packedCheck);
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entry = $context->builder->inBoundsGep($values, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isHt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $htType);
        $context->builder->branchIf($isHt, $packedRecurse, $packedSkip);

        $context->builder->positionAtEnd($packedRecurse);
        $child = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $entry
        );
        $sub = $context->builder->call(
            $context->lookupFunction('__hashtable__countRecursive'),
            $child
        );
        $total = $context->builder->load($totalSlot);
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($total, $sub),
            $totalSlot
        );
        $context->builder->branch($packedSkip);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedAdvance);

        $context->builder->positionAtEnd($packedAdvance);
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($context->builder->load($idxSlot), $one),
            $idxSlot
        );
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedDone);

        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'count_rec_str_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $id = (string) (++self::$blockSerial);
        $strHead = $fn->appendBasicBlock('count_rec_str_head_'.$id);
        $strBody = $fn->appendBasicBlock('count_rec_str_body_'.$id);
        $strRecurse = $fn->appendBasicBlock('count_rec_str_recurse_'.$id);
        $strNext = $fn->appendBasicBlock('count_rec_str_next_'.$id);
        $strDone = $fn->appendBasicBlock('count_rec_str_done_'.$id);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $isHt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $htType);
        $context->builder->branchIf($isHt, $strRecurse, $strNext);

        $context->builder->positionAtEnd($strRecurse);
        $child = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valEntry
        );
        $sub = $context->builder->call(
            $context->lookupFunction('__hashtable__countRecursive'),
            $child
        );
        $total = $context->builder->load($totalSlot);
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($total, $sub),
            $totalSlot
        );
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $node = $context->builder->load($walkSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        $context->builder->returnValue($context->builder->load($totalSlot));
    }
}
