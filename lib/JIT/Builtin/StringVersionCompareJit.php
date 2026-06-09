<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_version_compare (mirrors VmInfo::phpVersionCompare / former phpc_version_compare.c, #6277).
 *
 * php-src: ext/standard/versioning.c — php_version_compare
 */
final class StringVersionCompareJit
{
    /** @var list<array{string, int}> */
    private const SPECIAL_FORMS = [
        ['dev', 0],
        ['alpha', 1],
        ['a', 1],
        ['beta', 2],
        ['b', 2],
        ['RC', 3],
        ['rc', 3],
        ['#', 4],
        ['pl', 5],
        ['p', 5],
    ];

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);

        $probe = $context->module->getNamedFunction('__compiler_version_compare');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_version_compare', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_compare_special_version_forms', self::emitCompareSpecialForms(...));
        self::implementIfMissing($context, '__phpc_canonicalize_version', self::emitCanonicalizeVersion(...));
        self::implementIfMissing($context, '__phpc_version_compare_chars', self::emitVersionCompareChars(...));
        self::implementIfMissing($context, '__compiler_version_compare', self::emitCompilerVersionCompare(...));

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
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        return match ($name) {
            '__phpc_compare_special_version_forms' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i8p)
            ),
            '__phpc_canonicalize_version' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p)
            ),
            '__phpc_version_compare_chars' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i8p)
            ),
            '__compiler_version_compare' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown version_compare JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'strncmp', $context->context->functionType($i32, false, $i8p, $i8p, $sizeT));
        self::ensureExternal($context, 'strchr', $context->context->functionType($i8p, false, $i8p, $i32));
        self::ensureExternal(
            $context,
            'strtol',
            $context->context->functionType($i64, false, $i8p, $i8pp, $i32)
        );
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitCompareSpecialForms(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $form1 = $fn->getParam(0);
        $form2 = $fn->getParam(1);
        $negOne = $i32->constInt(-1, true);
        $found1Slot = BasicBlockHelper::entryAlloca($context, $i32);
        $found2Slot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($negOne, $found1Slot);
        $context->builder->store($negOne, $found2Slot);

        $next = $entry;
        foreach (self::SPECIAL_FORMS as [$literal, $order]) {
            $check = $fn->appendBasicBlock('sf_check_'.str_replace('#', 'hash', $literal));
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $litGlobal = $context->constantStringFromString($literal);
            $litPtr = $context->builder->load($litGlobal);
            $litMap = $context->structFieldMap['__string__'];
            $litData = $context->builder->structGep($litPtr, $litMap['value']);
            $litLen = $context->builder->load($context->builder->structGep($litPtr, $litMap['length']));
            $litLenSize = $context->builder->truncOrBitCast($litLen, $sizeT);

            $still1 = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($found1Slot),
                $negOne
            );
            $match1Bb = $fn->appendBasicBlock('sf_m1_'.str_replace('#', 'hash', $literal));
            $after1Bb = $fn->appendBasicBlock('sf_a1_'.str_replace('#', 'hash', $literal));
            $context->builder->branchIf($still1, $match1Bb, $after1Bb);
            $context->builder->positionAtEnd($match1Bb);
            $cmp1 = $context->builder->call(
                $context->lookupFunction('strncmp'),
                $form1,
                $litData,
                $litLenSize
            );
            $hit1 = $context->builder->icmp(Builder::INT_EQ, $cmp1, $i32->constInt(0, false));
            $set1Bb = $fn->appendBasicBlock('sf_s1_'.str_replace('#', 'hash', $literal));
            $context->builder->branchIf($hit1, $set1Bb, $after1Bb);
            $context->builder->positionAtEnd($set1Bb);
            $context->builder->store($i32->constInt($order, true), $found1Slot);
            $context->builder->branch($after1Bb);
            $context->builder->positionAtEnd($after1Bb);

            $still2 = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($found2Slot),
                $negOne
            );
            $match2Bb = $fn->appendBasicBlock('sf_m2_'.str_replace('#', 'hash', $literal));
            $after2Bb = $fn->appendBasicBlock('sf_a2_'.str_replace('#', 'hash', $literal));
            $context->builder->branchIf($still2, $match2Bb, $after2Bb);
            $context->builder->positionAtEnd($match2Bb);
            $cmp2 = $context->builder->call(
                $context->lookupFunction('strncmp'),
                $form2,
                $litData,
                $litLenSize
            );
            $hit2 = $context->builder->icmp(Builder::INT_EQ, $cmp2, $i32->constInt(0, false));
            $set2Bb = $fn->appendBasicBlock('sf_s2_'.str_replace('#', 'hash', $literal));
            $context->builder->branchIf($hit2, $set2Bb, $after2Bb);
            $context->builder->positionAtEnd($set2Bb);
            $context->builder->store($i32->constInt($order, true), $found2Slot);
            $context->builder->branch($after2Bb);
            $next = $after2Bb;
        }

        $done = $fn->appendBasicBlock('sf_done');
        $context->builder->positionAtEnd($next);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        $f1 = $context->builder->load($found1Slot);
        $f2 = $context->builder->load($found2Slot);
        $eq = $context->builder->icmp(Builder::INT_EQ, $f1, $f2);
        $gt = $context->builder->icmp(Builder::INT_SGT, $f1, $f2);
        $retZero = $fn->appendBasicBlock('sf_ret0');
        $retPos = $fn->appendBasicBlock('sf_retp');
        $retNeg = $fn->appendBasicBlock('sf_retn');
        $retMid = $fn->appendBasicBlock('sf_retm');
        $context->builder->branchIf($eq, $retZero, $retMid);
        $context->builder->positionAtEnd($retMid);
        $context->builder->branchIf($gt, $retPos, $retNeg);
        $context->builder->positionAtEnd($retPos);
        $context->builder->returnValue($i32->constInt(1, true));
        $context->builder->positionAtEnd($retNeg);
        $context->builder->returnValue($i32->constInt(-1, true));
        $context->builder->positionAtEnd($retZero);
        $context->builder->returnValue($i32->constInt(0, true));
    }

    private static function emitCanonicalizeVersion(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $version = $fn->getParam(0);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $dot = $i8->constInt(ord('.'), false);

        $len = $context->builder->call($context->lookupFunction('strlen'), $version);
        $lenI64 = $context->builder->zExt($len, $i64);
        $emptyBb = $fn->appendBasicBlock('canon_empty');
        $workBb = $fn->appendBasicBlock('canon_work');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyBuf = $context->builder->pointerCast(
            $context->builder->call($context->lookupFunction('malloc'), $sizeT->constInt(1, false)),
            $i8p
        );
        $context->builder->store($i8->constInt(0, false), $emptyBuf);
        $context->builder->returnValue($emptyBuf);

        $context->builder->positionAtEnd($workBb);
        $allocSize = $context->builder->addNoSignedWrap(
            $context->builder->mul($lenI64, $i64->constInt(2, false)),
            $one
        );
        $buf = $context->builder->pointerCast(
            $context->builder->call(
                $context->lookupFunction('malloc'),
                $context->builder->truncOrBitCast($allocSize, $sizeT)
            ),
            $i8p
        );
        $qSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $lpSlot = BasicBlockHelper::entryAlloca($context, $i8);
        $context->builder->store($buf, $qSlot);
        $firstCh = $context->builder->load($version);
        $context->builder->store($firstCh, $buf);
        $context->builder->store($context->builder->inBoundsGEP($buf, $one), $qSlot);
        $context->builder->store($firstCh, $lpSlot);
        $context->builder->store($one, $iSlot);

        $loopHead = $fn->appendBasicBlock('canon_head');
        $loopBody = $fn->appendBasicBlock('canon_body');
        $loopDone = $fn->appendBasicBlock('canon_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $i, $lenI64);
        $context->builder->branchIf($past, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($version, $i));
        $lp = $context->builder->load($lpSlot);
        $q = $context->builder->load($qSlot);
        $lq = $context->builder->load($context->builder->inBoundsGEP($q, self::ptrDiffFromBase($context, $q, $buf)));

        $emitCh = $fn->appendBasicBlock('canon_emit');
        $skipEmit = $fn->appendBasicBlock('canon_skip');
        $afterEmit = $fn->appendBasicBlock('canon_after_emit');
        $isSep = self::charIsOneOf($context, $ch, ['-', '_', '+']);
        $needDot = $context->builder->icmp(Builder::INT_NE, $lq, $dot);

        $context->builder->branchIf($isSep, $skipEmit, $emitCh);
        $context->builder->positionAtEnd($skipEmit);
        $context->builder->branchIf($needDot, $fn->appendBasicBlock('canon_dot_sep'), $afterEmit);
        $dotSep = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($dotSep);
        $q = $context->builder->load($qSlot);
        $context->builder->store($dot, $q);
        $context->builder->store($context->builder->inBoundsGEP($q, $one), $qSlot);
        $context->builder->branch($afterEmit);

        $context->builder->positionAtEnd($emitCh);
        $lpDigit = self::isDigitChar($context, $lp);
        $chDigit = self::isDigitChar($context, $ch);
        $boundary = $context->builder->or(
            $context->builder->and(
                $context->builder->and($context->builder->not($lpDigit), $context->builder->icmp(Builder::INT_NE, $lp, $dot)),
                $chDigit
            ),
            $context->builder->and(
                $lpDigit,
                $context->builder->and($context->builder->not($chDigit), $context->builder->icmp(Builder::INT_NE, $ch, $dot))
            )
        );
        $isAlnum = self::isAlnumChar($context, $ch);
        $emitPlain = $fn->appendBasicBlock('canon_plain');
        $emitBoundary = $fn->appendBasicBlock('canon_bound');
        $emitPunct = $fn->appendBasicBlock('canon_punct');
        $context->builder->branchIf($boundary, $emitBoundary, $emitPlain);
        $context->builder->positionAtEnd($emitPlain);
        $context->builder->branchIf($isAlnum, $fn->appendBasicBlock('canon_store'), $emitPunct);
        $storeBb = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($storeBb);
        $q = $context->builder->load($qSlot);
        $context->builder->store($ch, $q);
        $context->builder->store($context->builder->inBoundsGEP($q, $one), $qSlot);
        $context->builder->branch($afterEmit);

        $context->builder->positionAtEnd($emitBoundary);
        $context->builder->branchIf($needDot, $fn->appendBasicBlock('canon_dot_bound'), $fn->appendBasicBlock('canon_store_bound'));
        $dotBound = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 2];
        $storeBound = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($dotBound);
        $q = $context->builder->load($qSlot);
        $context->builder->store($dot, $q);
        $context->builder->store($context->builder->inBoundsGEP($q, $one), $qSlot);
        $context->builder->branch($storeBound);
        $context->builder->positionAtEnd($storeBound);
        $q = $context->builder->load($qSlot);
        $context->builder->store($ch, $q);
        $context->builder->store($context->builder->inBoundsGEP($q, $one), $qSlot);
        $context->builder->branch($afterEmit);

        $context->builder->positionAtEnd($emitPunct);
        $context->builder->branchIf($needDot, $fn->appendBasicBlock('canon_dot_punct'), $afterEmit);
        $dotPunct = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($dotPunct);
        $q = $context->builder->load($qSlot);
        $context->builder->store($dot, $q);
        $context->builder->store($context->builder->inBoundsGEP($q, $one), $qSlot);
        $context->builder->branch($afterEmit);

        $context->builder->positionAtEnd($afterEmit);
        $context->builder->store($ch, $lpSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $q = $context->builder->load($qSlot);
        $qOff = self::ptrDiffFromBase($context, $q, $buf);
        $last = $context->builder->load($context->builder->inBoundsGEP($q, $qOff));
        $trailDot = $context->builder->icmp(Builder::INT_EQ, $last, $dot);
        $trimBb = $fn->appendBasicBlock('canon_trim');
        $finishBb = $fn->appendBasicBlock('canon_finish');
        $context->builder->branchIf($trailDot, $trimBb, $finishBb);
        $context->builder->positionAtEnd($trimBb);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($q, $qOff));
        $context->builder->branch($finishBb);
        $context->builder->positionAtEnd($finishBb);
        $context->builder->store($i8->constInt(0, false), $q);
        $context->builder->returnValue($buf);
    }

    private static function emitVersionCompareChars(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $voidPtr = $context->getTypeFromString('void*');
        $orig1 = $fn->getParam(0);
        $orig2 = $fn->getParam(1);
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, true);
        $one = $i32->constInt(1, true);
        $negOne = $i32->constInt(-1, true);
        $hashCh = $i8->constInt(ord('#'), false);
        $dotI32 = $i32->constInt(ord('.'), false);
        $nHashGlobal = $context->constantStringFromString('#N#');
        $nHashPtr = $context->builder->load($nHashGlobal);
        $nHashData = $context->builder->structGep($nHashPtr, $context->structFieldMap['__string__']['value']);

        $empty1 = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $orig1, $nullPtr),
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($orig1), $i8->constInt(0, false))
        );
        $empty2 = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $orig2, $nullPtr),
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($orig2), $i8->constInt(0, false))
        );
        $bothEmpty = $context->builder->and($empty1, $empty2);
        $retZero = $fn->appendBasicBlock('vc_ret0');
        $retPos = $fn->appendBasicBlock('vc_retp');
        $retNeg = $fn->appendBasicBlock('vc_retn');
        $prep = $fn->appendBasicBlock('vc_prep');
        $notBoth = $fn->appendBasicBlock('vc_not_both');
        $checkOnly2 = $fn->appendBasicBlock('vc_check_only2');
        $context->builder->branchIf($bothEmpty, $retZero, $notBoth);
        $context->builder->positionAtEnd($notBoth);
        $only1 = $context->builder->and($empty1, $context->builder->not($empty2));
        $context->builder->branchIf($only1, $retNeg, $checkOnly2);
        $context->builder->positionAtEnd($checkOnly2);
        $only2 = $context->builder->and($context->builder->not($empty1), $empty2);
        $context->builder->branchIf($only2, $retPos, $prep);
        $context->builder->positionAtEnd($retZero);
        $context->builder->returnValue($zero);
        $context->builder->positionAtEnd($retPos);
        $context->builder->returnValue($one);
        $context->builder->positionAtEnd($retNeg);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($prep);
        $ver1Slot = BasicBlockHelper::entryAlloca($context, $i8p);
        $ver2Slot = BasicBlockHelper::entryAlloca($context, $i8p);
        self::prepareVersionBuffer($context, $fn, $orig1, $ver1Slot, $hashCh, 'v1');
        self::prepareVersionBuffer($context, $fn, $orig2, $ver2Slot, $hashCh, 'v2');

        $p1Slot = BasicBlockHelper::entryAlloca($context, $i8p);
        $p2Slot = BasicBlockHelper::entryAlloca($context, $i8p);
        $n1Slot = BasicBlockHelper::entryAlloca($context, $i8p);
        $n2Slot = BasicBlockHelper::entryAlloca($context, $i8p);
        $cmpSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($context->builder->load($ver1Slot), $p1Slot);
        $context->builder->store($context->builder->load($ver2Slot), $p2Slot);
        $context->builder->store($context->builder->load($ver1Slot), $n1Slot);
        $context->builder->store($context->builder->load($ver2Slot), $n2Slot);
        $context->builder->store($zero, $cmpSlot);

        $loopHead = $fn->appendBasicBlock('vc_loop_head');
        $loopBody = $fn->appendBasicBlock('vc_loop_body');
        $tail = $fn->appendBasicBlock('vc_tail');
        $cleanup = $fn->appendBasicBlock('vc_cleanup');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $p1 = $context->builder->load($p1Slot);
        $p2 = $context->builder->load($p2Slot);
        $hasP1 = $context->builder->icmp(Builder::INT_NE, $context->builder->load($p1), $i8->constInt(0, false));
        $hasP2 = $context->builder->icmp(Builder::INT_NE, $context->builder->load($p2), $i8->constInt(0, false));
        $cont = $context->builder->and($hasP1, $hasP2);
        $context->builder->branchIf($cont, $loopBody, $tail);

        $context->builder->positionAtEnd($loopBody);
        $p1 = $context->builder->load($p1Slot);
        $p2 = $context->builder->load($p2Slot);
        $n1Found = $context->builder->call($context->lookupFunction('strchr'), $p1, $dotI32);
        $n2Found = $context->builder->call($context->lookupFunction('strchr'), $p2, $dotI32);
        $context->builder->store($n1Found, $n1Slot);
        $context->builder->store($n2Found, $n2Slot);
        $hasDot1 = $context->builder->icmp(Builder::INT_NE, $n1Found, $nullPtr);
        $hasDot2 = $context->builder->icmp(Builder::INT_NE, $n2Found, $nullPtr);
        $afterDot1 = $fn->appendBasicBlock('vc_dot1');
        $setDot1 = $fn->appendBasicBlock('vc_set_dot1');
        $context->builder->branchIf($hasDot1, $setDot1, $afterDot1);
        $context->builder->positionAtEnd($setDot1);
        $context->builder->store($i8->constInt(0, false), $n1Found);
        $context->builder->branch($afterDot1);
        $context->builder->positionAtEnd($afterDot1);
        $afterDot2 = $fn->appendBasicBlock('vc_dot2');
        $setDot2 = $fn->appendBasicBlock('vc_set_dot2');
        $context->builder->branchIf($hasDot2, $setDot2, $afterDot2);
        $context->builder->positionAtEnd($setDot2);
        $context->builder->store($i8->constInt(0, false), $n2Found);
        $context->builder->branch($afterDot2);

        $context->builder->positionAtEnd($afterDot2);
        $seg1 = $context->builder->load($p1Slot);
        $seg2 = $context->builder->load($p2Slot);
        $d1 = self::isDigitChar($context, $context->builder->load($seg1));
        $d2 = self::isDigitChar($context, $context->builder->load($seg2));
        $digitCmp = $fn->appendBasicBlock('vc_digit_cmp');
        $specialCmp = $fn->appendBasicBlock('vc_special_cmp');
        $leftDigit = $fn->appendBasicBlock('vc_left_digit');
        $rightDigit = $fn->appendBasicBlock('vc_right_digit');
        $afterCmp = $fn->appendBasicBlock('vc_after_cmp');
        $bothDigit = $context->builder->and($d1, $d2);
        $bothNonDigit = $context->builder->and($context->builder->not($d1), $context->builder->not($d2));
        $context->builder->branchIf($bothDigit, $digitCmp, $fn->appendBasicBlock('vc_kind'));
        $kindBb = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($kindBb);
        $context->builder->branchIf($bothNonDigit, $specialCmp, $fn->appendBasicBlock('vc_mixed'));
        $mixedBb = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($mixedBb);
        $context->builder->branchIf($d1, $leftDigit, $rightDigit);

        $context->builder->positionAtEnd($digitCmp);
        $end1 = BasicBlockHelper::entryAlloca($context, $i8p);
        $end2 = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($nullPtr, $end1);
        $context->builder->store($nullPtr, $end2);
        $l1 = $context->builder->call($context->lookupFunction('strtol'), $seg1, $end1, $i32->constInt(10, false));
        $l2 = $context->builder->call($context->lookupFunction('strtol'), $seg2, $end2, $i32->constInt(10, false));
        $context->builder->store(self::normalizeCompareDelta($context, $context->builder->sub($l1, $l2)), $cmpSlot);
        $context->builder->branch($afterCmp);

        $context->builder->positionAtEnd($specialCmp);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__phpc_compare_special_version_forms'), $seg1, $seg2),
            $cmpSlot
        );
        $context->builder->branch($afterCmp);

        $context->builder->positionAtEnd($leftDigit);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__phpc_compare_special_version_forms'), $nHashData, $seg2),
            $cmpSlot
        );
        $context->builder->branch($afterCmp);

        $context->builder->positionAtEnd($rightDigit);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__phpc_compare_special_version_forms'), $seg1, $nHashData),
            $cmpSlot
        );
        $context->builder->branch($afterCmp);

        $context->builder->positionAtEnd($afterCmp);
        $cmp = $context->builder->load($cmpSlot);
        $advance = $fn->appendBasicBlock('vc_advance');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $cmp, $zero), $cleanup, $advance);
        $context->builder->positionAtEnd($advance);
        $n1 = $context->builder->load($n1Slot);
        $n2 = $context->builder->load($n2Slot);
        $afterAdv1 = $fn->appendBasicBlock('vc_adv1');
        $adv1 = $fn->appendBasicBlock('vc_do_adv1');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $n1, $nullPtr), $adv1, $afterAdv1);
        $context->builder->positionAtEnd($adv1);
        $context->builder->store($context->builder->inBoundsGEP($n1, $i32->constInt(1, false)), $p1Slot);
        $context->builder->branch($afterAdv1);
        $context->builder->positionAtEnd($afterAdv1);
        $afterAdv2 = $fn->appendBasicBlock('vc_adv2');
        $adv2 = $fn->appendBasicBlock('vc_do_adv2');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $n2, $nullPtr), $adv2, $afterAdv2);
        $context->builder->positionAtEnd($adv2);
        $context->builder->store($context->builder->inBoundsGEP($n2, $i32->constInt(1, false)), $p2Slot);
        $context->builder->branch($afterAdv2);
        $context->builder->positionAtEnd($afterAdv2);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($tail);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($cmpSlot), $zero),
            $fn->appendBasicBlock('vc_tail_work'),
            $cleanup
        );
        $tailWork = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($tailWork);
        $n1 = $context->builder->load($n1Slot);
        $n2 = $context->builder->load($n2Slot);
        $tailN1 = $fn->appendBasicBlock('vc_tail_n1');
        $tailN2 = $fn->appendBasicBlock('vc_tail_n2');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $n1, $nullPtr), $tailN1, $tailN2);
        $context->builder->positionAtEnd($tailN1);
        $p1 = $context->builder->load($p1Slot);
        $context->builder->branchIf(
            self::isDigitChar($context, $context->builder->load($p1)),
            $fn->appendBasicBlock('vc_tail_pos'),
            $fn->appendBasicBlock('vc_tail_rec1')
        );
        $tailPos = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 2];
        $tailRec1 = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($tailPos);
        $context->builder->store($one, $cmpSlot);
        $context->builder->branch($cleanup);
        $context->builder->positionAtEnd($tailRec1);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__phpc_version_compare_chars'), $p1, $nHashData),
            $cmpSlot
        );
        $context->builder->branch($cleanup);
        $context->builder->positionAtEnd($tailN2);
        $p2 = $context->builder->load($p2Slot);
        $context->builder->branchIf(
            self::isDigitChar($context, $context->builder->load($p2)),
            $fn->appendBasicBlock('vc_tail_neg'),
            $fn->appendBasicBlock('vc_tail_rec2')
        );
        $tailNeg = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 2];
        $tailRec2 = $fn->getBasicBlockList()[\count($fn->getBasicBlockList()) - 1];
        $context->builder->positionAtEnd($tailNeg);
        $context->builder->store($negOne, $cmpSlot);
        $context->builder->branch($cleanup);
        $context->builder->positionAtEnd($tailRec2);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__phpc_version_compare_chars'), $nHashData, $p2),
            $cmpSlot
        );
        $context->builder->branch($cleanup);

        $context->builder->positionAtEnd($cleanup);
        $cmp = $context->builder->load($cmpSlot);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($ver1Slot));
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($ver2Slot));
        $context->builder->returnValue($cmp);
    }

    private static function prepareVersionBuffer(
        Context $context,
        LlvmFunction $fn,
        Value $orig,
        Value $slot,
        Value $hashCh,
        string $tag
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $hash = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($orig), $hashCh);
        $canon = $fn->appendBasicBlock($tag.'_canon');
        $dup = $fn->appendBasicBlock($tag.'_dup');
        $after = $fn->appendBasicBlock($tag.'_after');
        $context->builder->branchIf($hash, $dup, $canon);
        $context->builder->positionAtEnd($canon);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__phpc_canonicalize_version'), $orig),
            $slot
        );
        $context->builder->branch($after);
        $context->builder->positionAtEnd($dup);
        $len = $context->builder->call($context->lookupFunction('strlen'), $orig);
        $raw = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->addNoSignedWrap($len, $sizeT->constInt(1, false))
        );
        $buf = $context->builder->pointerCast($raw, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($buf),
            $context->bytePtr($orig),
            $len
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $len));
        $context->builder->store($buf, $slot);
        $context->builder->branch($after);
        $context->builder->positionAtEnd($after);
    }

    private static function emitCompilerVersionCompare(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $map = $context->structFieldMap['__string__'];
        $ver1 = $fn->getParam(0);
        $ver2 = $fn->getParam(1);
        $emptyGlobal = $context->constantStringFromString('');
        $emptyPtr = $context->builder->load($emptyGlobal);
        $emptyData = $context->builder->structGep($emptyPtr, $map['value']);
        $nullStr = $strPtr->constNull();

        $s1 = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ver1, $nullStr),
            $emptyData,
            $context->builder->structGep($ver1, $map['value'])
        );
        $s2 = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ver2, $nullStr),
            $emptyData,
            $context->builder->structGep($ver2, $map['value'])
        );
        $cmp = $context->builder->call(
            $context->lookupFunction('__phpc_version_compare_chars'),
            $s1,
            $s2
        );
        $context->builder->returnValue($context->builder->zExt($cmp, $i64));
    }

    private static function isDigitChar(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('9'), false))
        );
    }

    private static function isAlnumChar(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            self::isDigitChar($context, $ch),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('a'), false)),
                    $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('z'), false))
                ),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), false)),
                    $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), false))
                )
            )
        );
    }

    /**
     * @param list<string> $chars
     */
    private static function charIsOneOf(Context $context, Value $ch, array $chars): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $result = $i1->constInt(0, false);
        foreach ($chars as $literal) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $ch,
                $context->getTypeFromString('int8')->constInt(ord($literal), false)
            );
            $result = $context->builder->or($match, $result);
        }

        return $result;
    }

    private static function normalizeCompareDelta(Context $context, Value $delta): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $deltaI32 = $context->builder->trunc($delta, $i32);
        $gt = $context->builder->icmp(Builder::INT_SGT, $deltaI32, $i32->constInt(0, true));
        $lt = $context->builder->icmp(Builder::INT_SLT, $deltaI32, $i32->constInt(0, true));

        return $context->builder->select(
            $gt,
            $i32->constInt(1, true),
            $context->builder->select($lt, $i32->constInt(-1, true), $i32->constInt(0, true))
        );
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ptrDiffFromBase(Context $context, Value $ptr, Value $base): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sub(
            $context->builder->ptrToInt($ptr, $i64),
            $context->builder->ptrToInt($base, $i64)
        );
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
