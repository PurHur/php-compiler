<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM get_meta_tags() runtime (ext/standard/php_meta_tags.c; #3703, #4608).
 *
 * Mirrors {@see \PHPCompiler\ext\standard\VmMetaTags::parseFromHtml()}.
 */
final class MetaTagsRuntime
{
    private const META_OPEN = '<meta';

    private const META_OPEN_LEN = 5;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (self::isFullyImplemented($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        IncludePathRuntime::ensureLinked($context);
        StringFileGetContents::implement($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');

        $probe = $context->module->getNamedFunction('__compiler_get_meta_tags');
        $ftGet = $context->context->functionType($htPtr, false, $strPtr, $i1);
        $fnGet = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_get_meta_tags', $ftGet);
        $context->registerFunction('__compiler_get_meta_tags', $fnGet);

        $ftParse = $context->context->functionType($htPtr, false, $strPtr);
        $fnParse = $context->module->getNamedFunction('__compiler_parse_meta_tags_html');
        if (null === $fnParse) {
            $fnParse = $context->module->addFunction('__compiler_parse_meta_tags_html', $ftParse);
        }
        $context->registerFunction('__compiler_parse_meta_tags_html', $fnParse);

        $ftExtract = $context->context->functionType($strPtr, false, $strPtr, $i8p);
        $fnExtract = $context->module->getNamedFunction('__compiler_meta_extract_attr');
        if (null === $fnExtract) {
            $fnExtract = $context->module->addFunction('__compiler_meta_extract_attr', $ftExtract);
        }
        $context->registerFunction('__compiler_meta_extract_attr', $fnExtract);

        $ftNorm = $context->context->functionType($strPtr, false, $strPtr);
        $fnNorm = $context->module->getNamedFunction('__compiler_meta_normalize_name');
        if (null === $fnNorm) {
            $fnNorm = $context->module->addFunction('__compiler_meta_normalize_name', $ftNorm);
        }
        $context->registerFunction('__compiler_meta_normalize_name', $fnNorm);

        if (0 === $fnExtract->countBasicBlocks()) {
            self::implementExtractAttribute($context, $fnExtract);
        }
        if (0 === $fnNorm->countBasicBlocks()) {
            self::implementNormalizeMetaName($context, $fnNorm);
        }
        if (0 === $fnParse->countBasicBlocks()) {
            self::implementParseMetaTagsHtml($context, $fnParse);
        }
        if (0 === $fnGet->countBasicBlocks()) {
            self::implementGetMetaTags($context, $fnGet);
        }

        self::registerLinkedRuntime($context);
    }

    private static function isFullyImplemented(Context $context): bool
    {
        foreach (
            [
                '__compiler_get_meta_tags',
                '__compiler_parse_meta_tags_html',
                '__compiler_meta_extract_attr',
                '__compiler_meta_normalize_name',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function implementGetMetaTags(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('meta_tags_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $useIncludePath = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        $resolveBlock = $fn->appendBasicBlock('meta_tags_resolve');
        $readBlock = $fn->appendBasicBlock('meta_tags_read');
        $context->builder->branchIf($useIncludePath, $resolveBlock, $readBlock);

        $context->builder->positionAtEnd($resolveBlock);
        $resolved = $context->builder->call(
            $context->lookupFunction('__compiler_stream_resolve_include_path'),
            $path
        );
        $hasResolved = $context->builder->icmp(Builder::INT_NE, $resolved, $nullStr);
        $useResolved = $fn->appendBasicBlock('meta_tags_use_resolved');
        $context->builder->branchIf($hasResolved, $useResolved, $readBlock);
        $context->builder->positionAtEnd($useResolved);
        $path = $resolved;
        $context->builder->branch($readBlock);

        $context->builder->positionAtEnd($readBlock);
        $html = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $path
        );
        $missing = $context->builder->icmp(Builder::INT_EQ, $html, $nullStr);
        $failBlock = $fn->appendBasicBlock('meta_tags_missing');
        $parseBlock = $fn->appendBasicBlock('meta_tags_parse');
        $context->builder->branchIf($missing, $failBlock, $parseBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($context->getTypeFromString('__hashtable__*')->constNull());

        $context->builder->positionAtEnd($parseBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__compiler_parse_meta_tags_html'),
            $html
        );
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function implementParseMetaTagsHtml(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('meta_parse_entry');
        $context->builder->positionAtEnd($entry);

        $html = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $metaOpenLit = self::literalCstr($context, self::META_OPEN);
        $nameLit = self::literalCstr($context, 'name');
        $contentLit = self::literalCstr($context, 'content');

        $len = $context->builder->load($context->builder->structGep($html, $map['length']));
        $bytesPtr = $context->builder->pointerCast(
            $context->builder->structGep($html, $map['value']),
            $i8p
        );
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $posAlloca = $context->builder->alloca($i64, 1, 'meta_pos');
        $context->builder->store($zeroI64, $posAlloca);

        $loopHead = $fn->appendBasicBlock('meta_loop_head');
        $loopBody = $fn->appendBasicBlock('meta_loop_body');
        $loopDone = $fn->appendBasicBlock('meta_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posAlloca);
        $remaining = $context->builder->sub($len, $pos);
        $canSearch = $context->builder->icmp(Builder::INT_SGE, $remaining, $i64->constInt(self::META_OPEN_LEN, false));
        $context->builder->branchIf($canSearch, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $metaPos = self::findCaseInsensitiveInBlock(
            $context,
            $fn,
            $bytesPtr,
            $len,
            $pos,
            $metaOpenLit,
            self::META_OPEN_LEN
        );
        $notFound = $context->builder->icmp(Builder::INT_SLT, $metaPos, $zeroI64);
        $afterFind = $fn->appendBasicBlock('meta_after_find');
        $context->builder->branchIf($notFound, $loopDone, $afterFind);

        $context->builder->positionAtEnd($afterFind);
        $gtRel = $context->builder->call(
            $context->lookupFunction('strchr'),
            $context->builder->inBoundsGEP($bytesPtr, $metaPos),
            $i32->constInt(ord('>'), false)
        );
        $noGt = $context->builder->icmp(Builder::INT_EQ, $gtRel, $i8p->constNull());
        $emitTag = $fn->appendBasicBlock('meta_emit');
        $context->builder->branchIf($noGt, $loopDone, $emitTag);

        $context->builder->positionAtEnd($emitTag);
        $gtPos = $context->builder->ptrToInt($gtRel, $i64);
        $basePos = $context->builder->ptrToInt($bytesPtr, $i64);
        $gtIndex = $context->builder->sub($gtPos, $basePos);
        $tagLen = $context->builder->add($context->builder->sub($gtIndex, $metaPos), $oneI64);
        $tagStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $tagLen,
            $context->builder->inBoundsGEP($bytesPtr, $metaPos)
        );
        $nameStr = $context->builder->call(
            $context->lookupFunction('__compiler_meta_extract_attr'),
            $tagStr,
            $nameLit
        );
        $contentStr = $context->builder->call(
            $context->lookupFunction('__compiler_meta_extract_attr'),
            $tagStr,
            $contentLit
        );
        $nameOk = $context->builder->icmp(Builder::INT_NE, $nameStr, $nullStr);
        $contentOk = $context->builder->icmp(Builder::INT_NE, $contentStr, $nullStr);
        $bothOk = $context->builder->and($nameOk, $contentOk);
        $storeBlock = $fn->appendBasicBlock('meta_store');
        $advanceBlock = $fn->appendBasicBlock('meta_advance');
        $context->builder->branchIf($bothOk, $storeBlock, $advanceBlock);

        $context->builder->positionAtEnd($storeBlock);
        $normName = $context->builder->call(
            $context->lookupFunction('__compiler_meta_normalize_name'),
            $nameStr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $normName,
            $contentStr
        );
        $context->builder->branch($advanceBlock);

        $context->builder->positionAtEnd($advanceBlock);
        $context->builder->store($context->builder->add($gtIndex, $oneI64), $posAlloca);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function implementExtractAttribute(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('meta_attr_entry');
        $context->builder->positionAtEnd($entry);

        $tagStr = $fn->getParam(0);
        $attrLit = $fn->getParam(1);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $negOne = $i64->constInt(-1, true);

        $tagLen = $context->builder->load($context->builder->structGep($tagStr, $map['length']));
        $tagBytes = $context->builder->pointerCast(
            $context->builder->structGep($tagStr, $map['value']),
            $i8p
        );
        $attrLen = $context->builder->call($context->lookupFunction('strlen'), $attrLit);
        $attrLenI64 = $context->builder->zExt($attrLen, $i64);

        $posSlot = $context->builder->alloca($i64, 1, 'attr_pos');
        $foundSlot = $context->builder->alloca($i64, 1, 'attr_found');
        $context->builder->store($zeroI64, $posSlot);
        $context->builder->store($negOne, $foundSlot);

        $searchHead = $fn->appendBasicBlock('attr_search_head');
        $searchBody = $fn->appendBasicBlock('attr_search_body');
        $searchDone = $fn->appendBasicBlock('attr_search_done');
        $fail = $fn->appendBasicBlock('attr_fail');
        $context->builder->branch($searchHead);

        $context->builder->positionAtEnd($searchHead);
        $pos = $context->builder->load($posSlot);
        $remaining = $context->builder->sub($tagLen, $pos);
        $can = $context->builder->icmp(Builder::INT_SGE, $remaining, $attrLenI64);
        $context->builder->branchIf($can, $searchBody, $searchDone);

        $context->builder->positionAtEnd($searchBody);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncasecmp'),
            $context->builder->inBoundsGEP($tagBytes, $pos),
            $attrLit,
            $attrLen
        );
        $matched = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $afterName = $fn->appendBasicBlock('attr_after_name');
        $advanceSearch = $fn->appendBasicBlock('attr_advance_search');
        $context->builder->branchIf($matched, $afterName, $advanceSearch);
        $context->builder->positionAtEnd($afterName);
        $context->builder->store($context->builder->add($pos, $attrLenI64), $foundSlot);
        $context->builder->branch($searchDone);
        $context->builder->positionAtEnd($advanceSearch);
        $context->builder->store($context->builder->add($pos, $oneI64), $posSlot);
        $context->builder->branch($searchHead);

        $context->builder->positionAtEnd($searchDone);
        $nameStart = $context->builder->load($foundSlot);
        $notFound = $context->builder->icmp(Builder::INT_SLT, $nameStart, $zeroI64);
        $parseVal = $fn->appendBasicBlock('attr_parse');
        $context->builder->branchIf($notFound, $fail, $parseVal);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($parseVal);
        $valPosSlot = $context->builder->alloca($i64, 1, 'attr_val_pos');
        $context->builder->store($nameStart, $valPosSlot);

        $skipWs = $fn->appendBasicBlock('attr_skip_ws');
        $expectEq = $fn->appendBasicBlock('attr_expect_eq');
        $readVal = $fn->appendBasicBlock('attr_read_val');
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($skipWs);
        $vpos = $context->builder->load($valPosSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $vpos, $tagLen);
        $context->builder->branchIf($pastEnd, $fail, $checkWs = $fn->appendBasicBlock('attr_chk_ws'));
        $context->builder->positionAtEnd($checkWs);
        $ch = $context->builder->load($context->builder->inBoundsGEP($tagBytes, $vpos));
        $isWs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false))
        );
        $nextWs = $fn->appendBasicBlock('attr_next_ws');
        $context->builder->branchIf($isWs, $nextWs, $expectEq);
        $context->builder->positionAtEnd($nextWs);
        $context->builder->store($context->builder->add($vpos, $oneI64), $valPosSlot);
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($expectEq);
        $vpos = $context->builder->load($valPosSlot);
        $eq = $context->builder->load($context->builder->inBoundsGEP($tagBytes, $vpos));
        $isEq = $context->builder->icmp(Builder::INT_EQ, $eq, $i8->constInt(ord('='), false));
        $afterEq = $fn->appendBasicBlock('attr_after_eq');
        $context->builder->branchIf($isEq, $afterEq, $fail);
        $context->builder->positionAtEnd($afterEq);
        $context->builder->store($context->builder->add($vpos, $oneI64), $valPosSlot);
        $context->builder->branch($readVal);

        $context->builder->positionAtEnd($readVal);
        $vpos = $context->builder->load($valPosSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $vpos, $tagLen);
        $context->builder->branchIf($pastEnd, $fail, $quoteChk = $fn->appendBasicBlock('attr_quote'));
        $context->builder->positionAtEnd($quoteChk);
        $quote = $context->builder->load($context->builder->inBoundsGEP($tagBytes, $vpos));
        $isDq = $context->builder->icmp(Builder::INT_EQ, $quote, $i8->constInt(ord('"'), false));
        $isSq = $context->builder->icmp(Builder::INT_EQ, $quote, $i8->constInt(ord("'"), false));
        $quoted = $context->builder->or($isDq, $isSq);
        $quotedPath = $fn->appendBasicBlock('attr_quoted');
        $unquoted = $fn->appendBasicBlock('attr_unquoted');
        $context->builder->branchIf($quoted, $quotedPath, $unquoted);

        $context->builder->positionAtEnd($quotedPath);
        $start = $context->builder->add($context->builder->load($valPosSlot), $oneI64);
        $context->builder->store($start, $valPosSlot);
        $qLoop = $fn->appendBasicBlock('attr_q_loop');
        $context->builder->branch($qLoop);
        $context->builder->positionAtEnd($qLoop);
        $vpos = $context->builder->load($valPosSlot);
        $doneQ = $context->builder->icmp(Builder::INT_SGE, $vpos, $tagLen);
        $context->builder->branchIf($doneQ, $fail, $qBody = $fn->appendBasicBlock('attr_q_body'));
        $context->builder->positionAtEnd($qBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($tagBytes, $vpos));
        $closed = $context->builder->icmp(Builder::INT_EQ, $ch, $quote);
        $qRet = $fn->appendBasicBlock('attr_q_ret');
        $qNext = $fn->appendBasicBlock('attr_q_next');
        $context->builder->branchIf($closed, $qRet, $qNext);
        $context->builder->positionAtEnd($qNext);
        $context->builder->store($context->builder->add($vpos, $oneI64), $valPosSlot);
        $context->builder->branch($qLoop);
        $context->builder->positionAtEnd($qRet);
        $end = $context->builder->load($valPosSlot);
        $valStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sub($end, $start),
            $context->builder->inBoundsGEP($tagBytes, $start)
        );
        $context->builder->returnValue($valStr);

        $context->builder->positionAtEnd($unquoted);
        $start = $context->builder->load($valPosSlot);
        $context->builder->store($start, $valPosSlot);
        $uLoop = $fn->appendBasicBlock('attr_u_loop');
        $context->builder->branch($uLoop);
        $context->builder->positionAtEnd($uLoop);
        $vpos = $context->builder->load($valPosSlot);
        $doneU = $context->builder->icmp(Builder::INT_SGE, $vpos, $tagLen);
        $uRet = $fn->appendBasicBlock('attr_u_ret');
        $context->builder->branchIf($doneU, $uRet, $uBody = $fn->appendBasicBlock('attr_u_body'));
        $context->builder->positionAtEnd($uBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($tagBytes, $vpos));
        $isStop = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('>'), false))
        );
        $uNext = $fn->appendBasicBlock('attr_u_next');
        $context->builder->branchIf($isStop, $uRet, $uNext);
        $context->builder->positionAtEnd($uNext);
        $context->builder->store($context->builder->add($vpos, $oneI64), $valPosSlot);
        $context->builder->branch($uLoop);
        $context->builder->positionAtEnd($uRet);
        $end = $context->builder->load($valPosSlot);
        $valStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sub($end, $start),
            $context->builder->inBoundsGEP($tagBytes, $start)
        );
        $context->builder->returnValue($valStr);
        $context->builder->clearInsertionPosition();
    }

    private static function implementNormalizeMetaName(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('meta_norm_entry');
        $context->builder->positionAtEnd($entry);

        $nameStr = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);

        $len = $context->builder->load($context->builder->structGep($nameStr, $map['length']));
        $bytes = $context->builder->pointerCast(
            $context->builder->structGep($nameStr, $map['value']),
            $i8p
        );
        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->add($len, $one)
        );
        $out = $context->builder->pointerCast($buf, $i8p);
        $posSlot = $context->builder->alloca($i64, 1, 'norm_pos');
        $context->builder->store($zero, $posSlot);

        $loop = $fn->appendBasicBlock('meta_norm_loop');
        $body = $fn->appendBasicBlock('meta_norm_body');
        $done = $fn->appendBasicBlock('meta_norm_done');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $pos = $context->builder->load($posSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $pos, $len),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($bytes, $pos));
        $lower = $context->builder->call(
            $context->lookupFunction('tolower'),
            $context->builder->zExt($ch, $context->getTypeFromString('int32'))
        );
        $lowerI8 = $context->builder->truncOrBitCast($lower, $i8);
        $isDot = $context->builder->icmp(Builder::INT_EQ, $lowerI8, $i8->constInt(ord('.'), false));
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $lowerI8, $i8->constInt(ord(' '), false));
        $outCh = $context->builder->select(
            $context->builder->or($isDot, $isSpace),
            $i8->constInt(ord('_'), false),
            $lowerI8
        );
        $context->builder->store($outCh, $context->builder->inBoundsGEP($out, $pos));
        $context->builder->store($context->builder->add($pos, $one), $posSlot);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($done);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $len));
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $len, $out)
        );
        $context->builder->clearInsertionPosition();
    }

    private static function findCaseInsensitiveInBlock(
        Context $context,
        LlvmFunction $fn,
        Value $bytesPtr,
        Value $len,
        Value $start,
        Value $needle,
        int $needleLen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $negOne = $i64->constInt(-1, true);
        $needleSize = $sizeT->constInt($needleLen, false);

        $posSlot = $context->builder->alloca($i64, 1, 'find_pos');
        $resultSlot = $context->builder->alloca($i64, 1, 'find_result');
        $context->builder->store($start, $posSlot);
        $context->builder->store($negOne, $resultSlot);

        $head = $fn->appendBasicBlock('meta_find_head');
        $body = $fn->appendBasicBlock('meta_find_body');
        $match = $fn->appendBasicBlock('meta_find_match');
        $advance = $fn->appendBasicBlock('meta_find_advance');
        $done = $fn->appendBasicBlock('meta_find_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posSlot);
        $remaining = $context->builder->sub($len, $pos);
        $can = $context->builder->icmp(Builder::INT_SGE, $remaining, $i64->constInt($needleLen, false));
        $context->builder->branchIf($can, $body, $done);

        $context->builder->positionAtEnd($body);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncasecmp'),
            $context->builder->inBoundsGEP($bytesPtr, $pos),
            $needle,
            $needleSize
        );
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isMatch, $match, $advance);

        $context->builder->positionAtEnd($match);
        $context->builder->store($pos, $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->add($pos, $oneI64), $posSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $len = \strlen($text);
        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $i64->constInt($len + 1, false)
        );
        $ptr = $context->builder->pointerCast($buf, $i8p);
        for ($i = 0; $i < $len; ++$i) {
            $context->builder->store(
                $i8->constInt(\ord($text[$i]), false),
                $context->builder->inBoundsGEP($ptr, $i64->constInt($i, false))
            );
        }
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($ptr, $i64->constInt($len, false))
        );

        return $ptr;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        foreach (
            [
                'strncasecmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
                'strchr' => [$i8p, false, [$i8p, $i32]],
                'tolower' => [$i32, false, [$i32]],
                'strlen' => [$sizeT, false, [$i8p]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            if (null === $context->module->getNamedFunction($name)) {
                $context->module->addFunction($name, $context->context->functionType($ret, $vararg, ...$params));
            }
            $context->registerFunction($name, $context->module->getNamedFunction($name));
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        foreach (
            [
                '__hashtable__alloc' => [$htPtr, false, []],
                '__hashtable__setStringKeyString' => [$voidTy, false, [$htPtr, $strPtr, $strPtr]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            if (null === $context->module->getNamedFunction($name)) {
                $context->module->addFunction($name, $context->context->functionType($ret, $vararg, ...$params));
            }
            $context->registerFunction($name, $context->module->getNamedFunction($name));
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__compiler_get_meta_tags',
                '__compiler_parse_meta_tags_html',
                '__compiler_meta_extract_attr',
                '__compiler_meta_normalize_name',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
