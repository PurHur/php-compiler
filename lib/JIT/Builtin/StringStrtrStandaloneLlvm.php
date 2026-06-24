<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStrReplace;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM body for __compiler_strtr_array — AOT standalone only (#9392).
 *
 * JIT embed uses {@see StrtrArrayJitHelper} PHP; standalone keeps LLVM walker until
 * HashTable iteration compiles in native standalone nested link.
 */
final class StringStrtrStandaloneLlvm
{
    private const PAIR_STRIDE = 32;

    public static function implement(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_strtr_array', self::implementArray(...));
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
        $fn = $context->lookupFunction($name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementArray(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $subject = $fn->getParam(0);
        $replacePairs = $fn->getParam(1);

        $map = $context->structFieldMap['__string__'];
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nodePtr = $context->getTypeFromString('__strkey_node__*');

        $slen = $context->builder->load($context->builder->structGep($subject, $map['length']));
        $emptySubj = $fn->appendBasicBlock('strtr_arr_empty_subj');
        $collect = $fn->appendBasicBlock('strtr_arr_collect');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $slen, $zero),
            $emptySubj,
            $collect
        );
        $context->builder->positionAtEnd($emptySubj);
        $context->builder->returnValue(self::emptyString($context));

        $context->builder->positionAtEnd($collect);
        $nullHt = $fn->appendBasicBlock('strtr_arr_null_ht');
        $doCollect = $fn->appendBasicBlock('strtr_arr_do_collect');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $replacePairs, $htPtr->constNull()),
            $nullHt,
            $doCollect
        );
        $context->builder->positionAtEnd($nullHt);
        $context->builder->returnValue(self::copyString($context, $subject));

        $context->builder->positionAtEnd($doCollect);
        $countSlot = $context->builder->alloca($i64, 1);
        $capSlot = $context->builder->alloca($i64, 1);
        $pairsSlot = $context->builder->alloca($i8p, 1);
        $context->builder->store($zero, $countSlot);
        $context->builder->store($zero, $capSlot);
        $context->builder->store($i8p->constNull(), $pairsSlot);

        self::collectStringKeys($context, $fn, $replacePairs, $slen, $countSlot, $capSlot, $pairsSlot);
        self::collectNumericKeys($context, $fn, $replacePairs, $slen, $countSlot, $capSlot, $pairsSlot);

        $afterCollect = $fn->appendBasicBlock('strtr_arr_after_collect');
        $context->builder->branch($afterCollect);
        $context->builder->positionAtEnd($afterCollect);

        $count = $context->builder->load($countSlot);
        $zeroCount = $fn->appendBasicBlock('strtr_arr_zero_count');
        $countBranch = $fn->appendBasicBlock('strtr_arr_count_branch');
        $oneCount = $fn->appendBasicBlock('strtr_arr_one_count');
        $manyCount = $fn->appendBasicBlock('strtr_arr_many_count');
        $isZero = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $count, $one);
        $context->builder->branchIf($isZero, $zeroCount, $countBranch);
        $context->builder->positionAtEnd($countBranch);
        $context->builder->branchIf($isOne, $oneCount, $manyCount);

        $context->builder->positionAtEnd($zeroCount);
        $context->builder->returnValue(self::copyString($context, $subject));

        $context->builder->positionAtEnd($oneCount);
        $pairs = $context->builder->load($pairsSlot);
        $keyLen = self::pairField($context, $pairs, $zero, 1);
        $keyPtr = self::pairFieldPtr($context, $pairs, $zero, 0);
        $valLen = self::pairField($context, $pairs, $zero, 3);
        $valPtr = self::pairFieldPtr($context, $pairs, $zero, 2);
        $singleChar = $context->builder->icmp(Builder::INT_EQ, $keyLen, $one);
        $singleCharBb = $fn->appendBasicBlock('strtr_arr_single_char');
        $multiCharBb = $fn->appendBasicBlock('strtr_arr_multi_char');
        $context->builder->branchIf($singleChar, $singleCharBb, $multiCharBb);

        $context->builder->positionAtEnd($singleCharBb);
        $subjCopy = self::copyString($context, $subject);
        $fromStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $one,
            $keyPtr
        );
        $toBuf = $context->builder->alloca($i8, 1);
        $toFirst = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $valLen, $zero),
            $context->builder->load($valPtr),
            $i8->constInt(0, false)
        );
        $context->builder->store($toFirst, $toBuf);
        $toStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $one,
            $toBuf
        );
        $singleResult = $context->builder->call(
            $context->lookupFunction('__compiler_strtr'),
            $subjCopy,
            $fromStr,
            $toStr
        );
        self::freePairs($context, $pairsSlot, $countSlot);
        $context->builder->returnValue($singleResult);

        $context->builder->positionAtEnd($multiCharBb);
        $keyStr = $context->builder->call($context->lookupFunction('__string__init'), $keyLen, $keyPtr);
        $valStr = $context->builder->call($context->lookupFunction('__string__init'), $valLen, $valPtr);
        $multiResult = JitStrReplace::replace($context, $keyStr, $valStr, $subject);
        self::freePairs($context, $pairsSlot, $countSlot);
        $context->builder->returnValue($multiResult);

        $context->builder->positionAtEnd($manyCount);
        $sdata = $context->builder->structGep($subject, $map['value']);
        self::longestMatch($context, $fn, $sdata, $slen, $pairsSlot, $countSlot);
    }

    private static function collectStringKeys(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $slen,
        Value $countSlot,
        Value $capSlot,
        Value $pairsSlot
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $map = $context->structFieldMap['__string__'];
        $nodePtr = $context->getTypeFromString('__strkey_node__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $nodeSlot = $context->builder->alloca($nodePtr, 1);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($ht, $htMap['strKeys'])),
            $nodeSlot
        );
        $head = $fn->appendBasicBlock('strtr_collect_str_head');
        $body = $fn->appendBasicBlock('strtr_collect_str_body');
        $done = $fn->appendBasicBlock('strtr_collect_str_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtr->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyLen = $context->builder->load($context->builder->structGep($keyStr, $map['length']));
        $skipKey = $fn->appendBasicBlock('strtr_collect_str_skip');
        $addKey = $fn->appendBasicBlock('strtr_collect_str_add');
        $tooLong = $context->builder->icmp(Builder::INT_SGT, $keyLen, $slen);
        $emptyKey = $context->builder->icmp(Builder::INT_EQ, $keyLen, $zero);
        $skip = $context->builder->or($tooLong, $emptyKey);
        $context->builder->branchIf($skip, $skipKey, $addKey);

        $context->builder->positionAtEnd($addKey);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valStr = self::valueToString($context, $fn, $valField);
        $keyPtr = $context->builder->structGep($keyStr, $map['value']);
        $valLen = $context->builder->load($context->builder->structGep($valStr, $map['length']));
        $valPtr = $context->builder->structGep($valStr, $map['value']);
        self::pairAdd($context, $fn, $countSlot, $capSlot, $pairsSlot, $keyPtr, $keyLen, $valPtr, $valLen);
        $context->builder->branch($skipKey);

        $context->builder->positionAtEnd($skipKey);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function collectNumericKeys(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $slen,
        Value $countSlot,
        Value $capSlot,
        Value $pairsSlot
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $indexSlot = $context->builder->alloca($i64, 1);
        $limit = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $context->builder->store($zero, $indexSlot);
        $valuesBase = $context->builder->load($context->builder->structGep($ht, $htMap['values']));

        $head = $fn->appendBasicBlock('strtr_collect_num_head');
        $body = $fn->appendBasicBlock('strtr_collect_num_body');
        $done = $fn->appendBasicBlock('strtr_collect_num_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $limit);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $valueMap = $context->structFieldMap['__value__'];
        $entry = $context->builder->gep($valuesBase, $idx);
        $typeByte = $context->builder->load($context->builder->structGep($entry, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(0, false));
        $skipBb = $fn->appendBasicBlock('strtr_collect_num_skip');
        $addBb = $fn->appendBasicBlock('strtr_collect_num_add');
        $context->builder->branchIf($isNull, $skipBb, $addBb);

        $context->builder->positionAtEnd($addBb);
        $numBuf = $context->builder->alloca($i8, 32, 'strtr_num_key');
        $fmt = $context->builder->pointerCast($context->constantFromString('%llu'), $context->getTypeFromString('int8*'));
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $numBuf,
            $i64->constInt(32, false),
            $fmt,
            $idx
        );
        $nI64 = $context->builder->sext($n, $i64);
        $badLen = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $nI64, $zero),
            $context->builder->icmp(Builder::INT_SGE, $nI64, $i64->constInt(32, false))
        );
        $skipLong = $fn->appendBasicBlock('strtr_collect_num_skip_long');
        $lenCheck = $fn->appendBasicBlock('strtr_collect_num_len_check');
        $doAdd = $fn->appendBasicBlock('strtr_collect_num_do_add');
        $context->builder->branchIf($badLen, $skipLong, $lenCheck);
        $context->builder->positionAtEnd($lenCheck);
        $tooLong = $context->builder->icmp(Builder::INT_SGT, $nI64, $slen);
        $context->builder->branchIf($tooLong, $skipLong, $doAdd);

        $context->builder->positionAtEnd($doAdd);
        $valStr = self::valueToString($context, $fn, $entry);
        $map = $context->structFieldMap['__string__'];
        $valLen = $context->builder->load($context->builder->structGep($valStr, $map['length']));
        $valPtr = $context->builder->structGep($valStr, $map['value']);
        self::pairAdd($context, $fn, $countSlot, $capSlot, $pairsSlot, $numBuf, $nI64, $valPtr, $valLen);
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipLong);
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function pairAdd(
        Context $context,
        LlvmFunction $fn,
        Value $countSlot,
        Value $capSlot,
        Value $pairsSlot,
        Value $keyPtr,
        Value $keyLen,
        Value $valPtr,
        Value $valLen
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $eight = $i64->constInt(8, false);
        $stride = $i64->constInt(self::PAIR_STRIDE, false);

        $count = $context->builder->load($countSlot);
        $cap = $context->builder->load($capSlot);
        $needGrow = $context->builder->icmp(Builder::INT_SGE, $count, $cap);
        $growBb = $fn->appendBasicBlock('strtr_pair_grow');
        $storeBb = $fn->appendBasicBlock('strtr_pair_store');
        $context->builder->branchIf($needGrow, $growBb, $storeBb);

        $context->builder->positionAtEnd($growBb);
        $newCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $cap, $zero),
            $i64->constInt(8, false),
            $context->builder->mul($cap, $i64->constInt(2, false))
        );
        $bytes = $context->builder->mul($newCap, $stride);
        $pairs = $context->builder->load($pairsSlot);
        $isFirst = $context->builder->icmp(Builder::INT_EQ, $cap, $zero);
        $grown = $context->builder->select(
            $isFirst,
            $context->builder->call(
                $context->lookupFunction('malloc'),
                $context->builder->truncOrBitCast($bytes, $sizeT)
            ),
            $context->builder->call(
                $context->lookupFunction('realloc'),
                $pairs,
                $context->builder->truncOrBitCast($bytes, $sizeT)
            )
        );
        $context->builder->store($context->builder->pointerCast($grown, $i8p), $pairsSlot);
        $context->builder->store($newCap, $capSlot);
        $context->builder->branch($storeBb);

        $context->builder->positionAtEnd($storeBb);
        $pairs = $context->builder->load($pairsSlot);
        $count = $context->builder->load($countSlot);
        $base = $context->builder->mul($count, $stride);
        $slot = $context->builder->gep($pairs, $base);

        $malloc = $context->lookupFunction('malloc');
        $keyCopy = $context->builder->call(
            $malloc,
            $context->builder->truncOrBitCast($keyLen, $sizeT)
        );
        $valCopy = $context->builder->call(
            $malloc,
            $context->builder->truncOrBitCast($valLen, $sizeT)
        );
        $context->intrinsic->memcpy($keyCopy, $keyPtr, $keyLen, false);
        $context->intrinsic->memcpy($valCopy, $valPtr, $valLen, false);

        $i64p = $context->getTypeFromString('int64*');
        $words = $context->builder->pointerCast($slot, $i64p);
        $context->builder->store(
            $context->builder->ptrToInt($keyCopy, $i64),
            $context->builder->gep($words, $zero)
        );
        $context->builder->store($keyLen, $context->builder->gep($words, $one));
        $context->builder->store(
            $context->builder->ptrToInt($valCopy, $i64),
            $context->builder->gep($words, $i64->constInt(2, false))
        );
        $context->builder->store($valLen, $context->builder->gep($words, $i64->constInt(3, false)));
        $context->builder->store($context->builder->addNoSignedWrap($count, $one), $countSlot);
    }

    private static function longestMatch(
        Context $context,
        LlvmFunction $fn,
        Value $sdata,
        Value $slen,
        Value $pairsSlot,
        Value $countSlot
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $count = $context->builder->load($countSlot);
        $pairs = $context->builder->load($pairsSlot);

        $minSlot = $context->builder->alloca($i64, 1);
        $maxSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($context->builder->add($slen, $one), $minSlot);
        $context->builder->store($zero, $maxSlot);
        $firstChars = $context->builder->alloca($i8, 256, 'strtr_first_chars');
        $lengths = $context->builder->alloca($i8, 256, 'strtr_lengths');
        $context->intrinsic->memset($firstChars, $i8->constInt(0, false), $i64->constInt(256, false), false);
        $context->intrinsic->memset($lengths, $i8->constInt(0, false), $i64->constInt(256, false), false);

        $scanSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $scanSlot);
        $scanHead = $fn->appendBasicBlock('strtr_lm_scan_head');
        $scanBody = $fn->appendBasicBlock('strtr_lm_scan_body');
        $scanDone = $fn->appendBasicBlock('strtr_lm_scan_done');
        $context->builder->branch($scanHead);
        $context->builder->positionAtEnd($scanHead);
        $si = $context->builder->load($scanSlot);
        $scanPast = $context->builder->icmp(Builder::INT_SGE, $si, $count);
        $context->builder->branchIf($scanPast, $scanDone, $scanBody);
        $context->builder->positionAtEnd($scanBody);
        $keyLen = self::pairField($context, $pairs, $si, 1);
        $keyPtr = self::pairFieldPtr($context, $pairs, $si, 0);
        $minv = $context->builder->load($minSlot);
        $maxv = $context->builder->load($maxSlot);
        $newMin = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $keyLen, $minv),
            $keyLen,
            $minv
        );
        $newMax = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $keyLen, $maxv),
            $keyLen,
            $maxv
        );
        $context->builder->store($newMin, $minSlot);
        $context->builder->store($newMax, $maxSlot);
        $first = $context->builder->load($keyPtr);
        $firstOrd = $context->builder->zExt($first, $i64);
        $context->builder->store($i8->constInt(1, false), $context->builder->gep($firstChars, $firstOrd));
        $lenOrd = $context->builder->trunc($keyLen, $i8);
        $context->builder->store($i8->constInt(1, false), $context->builder->gep($lengths, $context->builder->zExt($lenOrd, $i64)));
        $context->builder->store($context->builder->addNoSignedWrap($si, $one), $scanSlot);
        $context->builder->branch($scanHead);
        $context->builder->positionAtEnd($scanDone);

        $minlen = $context->builder->load($minSlot);
        $maxlen = $context->builder->load($maxSlot);
        $badRange = $fn->appendBasicBlock('strtr_lm_bad_range');
        $work = $fn->appendBasicBlock('strtr_lm_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $minlen, $maxlen),
            $badRange,
            $work
        );
        $context->builder->positionAtEnd($badRange);
        $subjectCopy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $slen,
            $sdata
        );
        self::freePairs($context, $pairsSlot, $countSlot);
        $context->builder->returnValue($subjectCopy);

        $context->builder->positionAtEnd($work);
        $outCapSlot = $context->builder->alloca($i64, 1);
        $initialCap = $context->builder->add($slen, $one);
        $context->builder->store($initialCap, $outCapSlot);
        $outBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($initialCap, $sizeT)
        );
        $outBufSlot = $context->builder->alloca($i8p, 1);
        $outBufChar = $context->builder->pointerCast($outBuf, $i8p);
        $context->builder->store($outBufChar, $outBufSlot);
        $outLenSlot = $context->builder->alloca($i64, 1);
        $posSlot = $context->builder->alloca($i64, 1);
        $oldPosSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $oldPosSlot);

        $loopHead = $fn->appendBasicBlock('strtr_lm_head');
        $loopBody = $fn->appendBasicBlock('strtr_lm_body');
        $loopDone = $fn->appendBasicBlock('strtr_lm_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posSlot);
        $limit = $context->builder->sub($slen, $minlen);
        $past = $context->builder->icmp(Builder::INT_SGT, $pos, $limit);
        $context->builder->branchIf($past, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($sdata, $pos));
        $ord = $context->builder->zExt($ch, $i64);
        $hasFirst = $context->builder->load($context->builder->gep($firstChars, $ord));
        $maybe = $context->builder->icmp(Builder::INT_NE, $hasFirst, $i8->constInt(0, false));
        $noMatch = $fn->appendBasicBlock('strtr_lm_no_match');
        $tryMatch = $fn->appendBasicBlock('strtr_lm_try_match');
        $context->builder->branchIf($maybe, $tryMatch, $noMatch);

        $context->builder->positionAtEnd($tryMatch);
        $tryLenSlot = $context->builder->alloca($i64, 1);
        $remain = $context->builder->sub($slen, $pos);
        $startTry = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $maxlen, $remain),
            $maxlen,
            $remain
        );
        $context->builder->store($startTry, $tryLenSlot);
        $tryHead = $fn->appendBasicBlock('strtr_lm_try_head');
        $tryBody = $fn->appendBasicBlock('strtr_lm_try_body');
        $tryFail = $fn->appendBasicBlock('strtr_lm_try_fail');
        $tryOk = $fn->appendBasicBlock('strtr_lm_try_ok');
        $context->builder->branch($tryHead);
        $context->builder->positionAtEnd($tryHead);
        $tryLen = $context->builder->load($tryLenSlot);
        $tooSmall = $context->builder->icmp(Builder::INT_SLT, $tryLen, $minlen);
        $context->builder->branchIf($tooSmall, $tryFail, $tryBody);
        $context->builder->positionAtEnd($tryBody);
        $lenFlag = $context->builder->load(
            $context->builder->gep($lengths, $context->builder->zExt($context->builder->trunc($tryLen, $i8), $i64))
        );
        $hasLen = $context->builder->icmp(Builder::INT_NE, $lenFlag, $i8->constInt(0, false));
        $noLen = $fn->appendBasicBlock('strtr_lm_no_len');
        $findPair = $fn->appendBasicBlock('strtr_lm_find_pair');
        $context->builder->branchIf($hasLen, $findPair, $noLen);
        $context->builder->positionAtEnd($findPair);
        $matchIdx = self::findPair($context, $fn, $pairs, $count, $sdata, $pos, $tryLen);
        $found = $context->builder->icmp(Builder::INT_SGE, $matchIdx, $zero);
        $context->builder->branchIf($found, $tryOk, $noLen);
        $context->builder->positionAtEnd($tryOk);
        $oldPos = $context->builder->load($oldPosSlot);
        $outLen = $context->builder->load($outLenSlot);
        $matchValLen = self::pairField($context, $pairs, $matchIdx, 3);
        $prefixLen = $context->builder->sub($pos, $oldPos);
        $need = $context->builder->add($outLen, $context->builder->add($prefixLen, $matchValLen));
        $outBufChar = $context->builder->load($outBufSlot);
        $currentCap = $context->builder->load($outCapSlot);
        $outBufChar = self::growBuffer($context, $fn, $outBufChar, $outCapSlot, $need, $currentCap);
        $context->builder->store($outBufChar, $outBufSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($outBufChar, $outLen),
            $context->builder->gep($sdata, $oldPos),
            $prefixLen,
            false
        );
        $outLen = $context->builder->addNoSignedWrap($outLen, $prefixLen);
        $valPtr = self::pairFieldPtr($context, $pairs, $matchIdx, 2);
        $context->intrinsic->memcpy(
            $context->builder->gep($outBufChar, $outLen),
            $valPtr,
            $matchValLen,
            false
        );
        $outLen = $context->builder->addNoSignedWrap($outLen, $matchValLen);
        $context->builder->store($outLen, $outLenSlot);
        $matchKeyLen = self::pairField($context, $pairs, $matchIdx, 1);
        $newOld = $context->builder->add($pos, $matchKeyLen);
        $context->builder->store($newOld, $oldPosSlot);
        $context->builder->store($context->builder->sub($newOld, $one), $posSlot);
        $context->builder->branch($noMatch);

        $context->builder->positionAtEnd($noLen);
        $context->builder->store($context->builder->sub($tryLen, $one), $tryLenSlot);
        $context->builder->branch($tryHead);
        $context->builder->positionAtEnd($tryFail);
        $context->builder->branch($noMatch);

        $context->builder->positionAtEnd($noMatch);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopDone);

        $outLen = $context->builder->load($outLenSlot);
        $hasOut = $context->builder->icmp(Builder::INT_SGT, $outLen, $zero);
        $noOut = $fn->appendBasicBlock('strtr_lm_no_out');
        $hasOutBb = $fn->appendBasicBlock('strtr_lm_has_out');
        $context->builder->branchIf($hasOut, $hasOutBb, $noOut);
        $context->builder->positionAtEnd($noOut);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($outBufSlot));
        $noOutRet = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $slen,
            $sdata
        );
        self::freePairs($context, $pairsSlot, $countSlot);
        $context->builder->returnValue($noOutRet);

        $context->builder->positionAtEnd($hasOutBb);
        $oldPos = $context->builder->load($oldPosSlot);
        $tail = $context->builder->sub($slen, $oldPos);
        $need = $context->builder->addNoSignedWrap($outLen, $tail);
        $outBufChar = $context->builder->load($outBufSlot);
        $currentCap = $context->builder->load($outCapSlot);
        $outBufChar = self::growBuffer($context, $fn, $outBufChar, $outCapSlot, $need, $currentCap);
        $context->builder->store($outBufChar, $outBufSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($outBufChar, $outLen),
            $context->builder->gep($sdata, $oldPos),
            $tail,
            false
        );
        $finalLen = $context->builder->addNoSignedWrap($outLen, $tail);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLen,
            $outBufChar
        );
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($outBufSlot));
        self::freePairs($context, $pairsSlot, $countSlot);
        $context->builder->returnValue($result);
    }

    private static function findPair(
        Context $context,
        LlvmFunction $fn,
        Value $pairs,
        Value $count,
        Value $haystack,
        Value $offset,
        Value $tryLen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $negOne = $i64->constInt(-1, true);

        $idxSlot = $context->builder->alloca($i64, 1);
        $resultSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($negOne, $resultSlot);

        $head = $fn->appendBasicBlock('strtr_find_head');
        $body = $fn->appendBasicBlock('strtr_find_body');
        $done = $fn->appendBasicBlock('strtr_find_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyLen = self::pairField($context, $pairs, $idx, 1);
        $lenMatch = $context->builder->icmp(Builder::INT_EQ, $keyLen, $tryLen);
        $nextBb = $fn->appendBasicBlock('strtr_find_next');
        $cmpBb = $fn->appendBasicBlock('strtr_find_cmp');
        $context->builder->branchIf($lenMatch, $cmpBb, $nextBb);
        $context->builder->positionAtEnd($cmpBb);
        $keyPtr = self::pairFieldPtr($context, $pairs, $idx, 0);
        $cmpOk = self::bytesEqual($context, $fn, $haystack, $offset, $keyPtr, $tryLen);
        $foundBb = $fn->appendBasicBlock('strtr_find_found');
        $context->builder->branchIf($cmpOk, $foundBb, $nextBb);
        $context->builder->positionAtEnd($foundBb);
        $context->builder->store($idx, $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function bytesEqual(
        Context $context,
        LlvmFunction $fn,
        Value $a,
        Value $aOff,
        Value $b,
        Value $len
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $true = $i1->constInt(1, false);
        $false = $i1->constInt(0, false);
        $resultSlot = $context->builder->alloca($i1, 1);

        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $head = $fn->appendBasicBlock('strtr_beq_head');
        $body = $fn->appendBasicBlock('strtr_beq_body');
        $ok = $fn->appendBasicBlock('strtr_beq_ok');
        $fail = $fn->appendBasicBlock('strtr_beq_fail');
        $done = $fn->appendBasicBlock('strtr_beq_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($past, $ok, $body);
        $context->builder->positionAtEnd($body);
        $ca = $context->builder->load($context->builder->gep($a, $context->builder->add($aOff, $i)));
        $cb = $context->builder->load($context->builder->gep($b, $i));
        $eq = $context->builder->icmp(Builder::INT_EQ, $ca, $cb);
        $cont = $fn->appendBasicBlock('strtr_beq_cont');
        $context->builder->branchIf($eq, $cont, $fail);
        $context->builder->positionAtEnd($cont);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($ok);
        $context->builder->store($true, $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($fail);
        $context->builder->store($false, $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function growBuffer(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $capSlot,
        Value $need,
        Value $currentCap
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $resultSlot = $context->builder->alloca($i8p, 1);
        $enough = $context->builder->icmp(Builder::INT_SLE, $need, $currentCap);
        $keep = $fn->appendBasicBlock('strtr_grow_keep');
        $grow = $fn->appendBasicBlock('strtr_grow_do');
        $done = $fn->appendBasicBlock('strtr_grow_done');
        $context->builder->branchIf($enough, $keep, $grow);
        $context->builder->positionAtEnd($keep);
        $context->builder->store($buf, $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($grow);
        $newCap = $context->builder->add($need, $i64->constInt(1, false));
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $buf,
            $context->builder->truncOrBitCast($newCap, $sizeT)
        );
        $context->builder->store($newCap, $capSlot);
        $context->builder->store($grown, $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function valueToString(Context $context, LlvmFunction $fn, Value $valField): Value
    {
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $resultSlot = $context->builder->alloca($strPtr, 1);
        $typeByte = $context->builder->load($context->builder->structGep($valField, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(0, false));
        $isString = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(4, false));
        $emptyBb = $fn->appendBasicBlock('strtr_v2s_empty');
        $checkBb = $fn->appendBasicBlock('strtr_v2s_check');
        $strBb = $fn->appendBasicBlock('strtr_v2s_string');
        $doneBb = $fn->appendBasicBlock('strtr_v2s_done');
        $context->builder->branchIf($isNull, $emptyBb, $checkBb);
        $context->builder->positionAtEnd($checkBb);
        $context->builder->branchIf($isString, $strBb, $emptyBb);
        $context->builder->positionAtEnd($emptyBb);
        $context->builder->store(self::emptyString($context), $resultSlot);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($strBb);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__value__readString'), $valField),
            $resultSlot
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function pairField(Context $context, Value $pairs, Value $index, int $fieldIndex): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $slot = self::pairSlot($context, $pairs, $index);
        $words = $context->builder->pointerCast($slot, $i64p);
        $raw = $context->builder->load($context->builder->gep($words, $i64->constInt($fieldIndex, false)));
        if (0 === $fieldIndex || 2 === $fieldIndex) {
            return $context->builder->ptrToInt($raw, $i64);
        }

        return $raw;
    }

    private static function pairFieldPtr(Context $context, Value $pairs, Value $index, int $fieldIndex): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = self::pairField($context, $pairs, $index, $fieldIndex);

        return $context->builder->intToPtr($raw, $i8p);
    }

    private static function pairSlot(Context $context, Value $pairs, Value $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $base = $context->builder->mul($index, $i64->constInt(self::PAIR_STRIDE, false));

        return $context->builder->gep($pairs, $base);
    }

    private static function freePairs(Context $context, Value $pairsSlot, Value $countSlot): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $pairs = $context->builder->load($pairsSlot);
        $count = $context->builder->load($countSlot);
        $idxSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $idxSlot);
        $free = $context->lookupFunction('free');
        $parent = $context->builder->getInsertBlock()?->getParent();
        if (null === $parent) {
            return;
        }
        $head = $parent->appendBasicBlock('strtr_free_head');
        $body = $parent->appendBasicBlock('strtr_free_body');
        $done = $parent->appendBasicBlock('strtr_free_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($past, $done, $body);
        $context->builder->positionAtEnd($body);
        $keyPtr = self::pairFieldPtr($context, $pairs, $idx, 0);
        $valPtr = self::pairFieldPtr($context, $pairs, $idx, 2);
        $context->builder->call($free, $keyPtr);
        $context->builder->call($free, $valPtr);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
        $context->builder->call($free, $pairs);
    }

    private static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
    }

    private static function copyString(Context $context, Value $subject): Value
    {
        $map = $context->structFieldMap['__string__'];
        $slen = $context->builder->load($context->builder->structGep($subject, $map['length']));
        $sdata = $context->builder->structGep($subject, $map['value']);

        return $context->builder->call($context->lookupFunction('__string__init'), $slen, $sdata);
    }
}
