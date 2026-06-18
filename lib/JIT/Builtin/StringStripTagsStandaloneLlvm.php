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
 * LLVM body for __compiler_strip_tags — AOT standalone only (#9196).
 *
 * JIT uses {@see StripTagsJitHelper} PHP; keep this until compiled PHP static storage is
 * reliable in native standalone link (same pattern as {@see LastErrorRuntimeLlvm}).
 */
final class StringStripTagsStandaloneLlvm
{
    private const ALLOWED_TAG_CAP = 32;

    private const TAG_NAME_CAP = 32;

    private const CONTENT_CAP = 256;

    private const PARSE_CONTENT_CAP = 128;

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureRuntime($context);

        foreach (
            [
                '__phpc_st_is_space',
                '__phpc_st_is_tag_char',
                '__phpc_st_find_substr',
                '__phpc_st_tolower_buf',
                '__phpc_st_extract_tag_name',
                '__phpc_st_tag_allowed',
                '__phpc_st_parse_allowed',
                '__compiler_strip_tags',
            ] as $name
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = self::declareFunction($context, $name);
                $context->registerFunction($name, $fn);
            }
        }

        self::implementIfMissing($context, '__phpc_st_is_space', self::emitIsSpace(...));
        self::implementIfMissing($context, '__phpc_st_is_tag_char', self::emitIsTagChar(...));
        self::implementIfMissing($context, '__phpc_st_find_substr', self::emitFindSubstr(...));
        self::implementIfMissing($context, '__phpc_st_tolower_buf', self::emitToLowerBuf(...));
        self::implementIfMissing($context, '__phpc_st_extract_tag_name', self::emitExtractTagName(...));
        self::implementIfMissing($context, '__phpc_st_tag_allowed', self::emitTagAllowed(...));
        self::implementIfMissing($context, '__phpc_st_parse_allowed', self::emitParseAllowed(...));
        self::implementIfMissing($context, '__compiler_strip_tags', self::emitStripTags(...));

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
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');

        return match ($name) {
            '__phpc_st_is_space' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_st_is_tag_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_st_find_substr' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $sizeT, $i8p, $sizeT, $sizeT)
            ),
            '__phpc_st_tolower_buf' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeT)
            ),
            '__phpc_st_extract_tag_name' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $sizeT, $i8p, $sizeT)
            ),
            '__phpc_st_tag_allowed' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i8p, $i32)
            ),
            '__phpc_st_parse_allowed' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $sizeT, $i8p, $i32)
            ),
            '__compiler_strip_tags' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown strip_tags helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
                ['realloc', $voidPtr, [$voidPtr, $sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
                ['memcmp', $i32, [$voidPtr, $voidPtr, $sizeT]],
                ['strcmp', $i32, [$i8p, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntime(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');

        foreach (
            [
                ['__string__strlen', $i64, [$strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
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

    private static function literalCstr(Context $context, string $text): Value
    {
        return $context->pointerFromStringConstant($text);
    }

    private static function emitIsSpace(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $ch = $fn->getParam(0);

        $isSpace = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\n"), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\r"), false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\v"), false)),
                            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\f"), false))
                        )
                    )
                )
            )
        );
        $context->builder->returnValue($context->builder->select($isSpace, $i32->constInt(1, false), $i32->constInt(0, false)));
    }

    private static function emitIsTagChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $ch = $fn->getParam(0);

        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('a'), false)),
            $context->builder->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('z'), false))
        );
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('A'), false)),
            $context->builder->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('Z'), false))
        );
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('9'), false))
        );
        $ok = $context->builder->or($isLower, $context->builder->or($isUpper, $isDigit));
        $context->builder->returnValue($context->builder->select($ok, $i32->constInt(1, false), $i32->constInt(0, false)));
    }

    private static function emitFindSubstr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $minusOne = $i32->constInt(-1, true);

        $hay = $fn->getParam(0);
        $hlen = $fn->getParam(1);
        $needle = $fn->getParam(2);
        $nlen = $fn->getParam(3);
        $from = $fn->getParam(4);

        $invalid = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $nlen, $sizeT->constInt(0, false)),
            $context->builder->icmp(
                Builder::INT_UGT,
                $context->builder->add($from, $nlen),
                $hlen
            )
        );
        $retNeg = $fn->appendBasicBlock('ret_neg');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $context->builder->branchIf($invalid, $retNeg, $loopHead);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->positionAtEnd($loopHead);
        $context->builder->store($from, $iSlot);
        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $canCheck = $context->builder->icmp(Builder::INT_ULE, $context->builder->add($i, $nlen), $hlen);
        $context->builder->branchIf($canCheck, $body, $done);

        $context->builder->positionAtEnd($body);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->bytePtr($context->builder->inBoundsGEP($hay, $i)),
            $context->bytePtr($needle),
            $nlen
        );
        $matched = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $found = $fn->appendBasicBlock('found');
        $next = $fn->appendBasicBlock('next');
        $context->builder->branchIf($matched, $found, $next);

        $context->builder->positionAtEnd($found);
        $context->builder->returnValue($context->builder->trunc($i, $i32));

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($retNeg);
        $context->builder->returnValue($minusOne);
    }

    private static function emitToLowerBuf(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $buf = $fn->getParam(0);
        $len = $fn->getParam(1);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $cont = $context->builder->icmp(Builder::INT_ULT, $i, $len);
        $context->builder->branchIf($cont, $body, $done);

        $context->builder->positionAtEnd($body);
        $ptr = $context->builder->inBoundsGEP($buf, $i);
        $ch = $context->builder->load($ptr);
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('A'), false)),
            $context->builder->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('Z'), false))
        );
        $lower = $context->builder->add(
            $context->builder->sub($ch, $i8->constInt(ord('A'), false)),
            $i8->constInt(ord('a'), false)
        );
        $context->builder->store($context->builder->select($isUpper, $lower, $ch), $ptr);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitExtractTagName(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);

        $content = $fn->getParam(0);
        $clen = $fn->getParam(1);
        $out = $fn->getParam(2);
        $outCap = $fn->getParam(3);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $startSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);

        $skipWs = $fn->appendBasicBlock('skip_ws');
        $afterWs = $fn->appendBasicBlock('after_ws');
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($skipWs);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $clen);
        $wsBody = $fn->appendBasicBlock('ws_body');
        $context->builder->branchIf($atEnd, $afterWs, $wsBody);

        $context->builder->positionAtEnd($wsBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($content, $i));
        $isWs = $context->i32Success($context->builder->call($context->lookupFunction('__phpc_st_is_space'), $ch));
        $wsNext = $fn->appendBasicBlock('ws_next');
        $context->builder->branchIf($isWs, $wsNext, $afterWs);
        $context->builder->positionAtEnd($wsNext);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($afterWs);
        $i = $context->builder->load($iSlot);
        $hitEnd = $context->builder->icmp(Builder::INT_UGE, $i, $clen);
        $ret0 = $fn->appendBasicBlock('ret0');
        $checkSlash = $fn->appendBasicBlock('check_slash');
        $context->builder->branchIf($hitEnd, $ret0, $checkSlash);

        $context->builder->positionAtEnd($checkSlash);
        $ch = $context->builder->load($context->builder->inBoundsGEP($content, $i));
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('/'), false));
        $afterSlash = $fn->appendBasicBlock('after_slash');
        $incSlash = $fn->appendBasicBlock('inc_slash');
        $context->builder->branchIf($isSlash, $incSlash, $afterSlash);
        $context->builder->positionAtEnd($incSlash);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($afterSlash);

        $context->builder->positionAtEnd($afterSlash);
        $i = $context->builder->load($iSlot);
        $hitEnd2 = $context->builder->icmp(Builder::INT_UGE, $i, $clen);
        $scanInit = $fn->appendBasicBlock('scan_init');
        $context->builder->branchIf($hitEnd2, $ret0, $scanInit);

        $context->builder->positionAtEnd($scanInit);
        $context->builder->store($i, $startSlot);
        $scanHead = $fn->appendBasicBlock('scan_head');
        $scanBody = $fn->appendBasicBlock('scan_body');
        $scanDone = $fn->appendBasicBlock('scan_done');
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanHead);
        $i = $context->builder->load($iSlot);
        $atEnd3 = $context->builder->icmp(Builder::INT_UGE, $i, $clen);
        $context->builder->branchIf($atEnd3, $scanDone, $scanBody);

        $context->builder->positionAtEnd($scanBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($content, $i));
        $isStop = $context->builder->or(
            $context->i32Success($context->builder->call($context->lookupFunction('__phpc_st_is_space'), $ch)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('>'), false)),
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('/'), false))
            )
        );
        $bad = $context->builder->not(
            $context->i32Success($context->builder->call($context->lookupFunction('__phpc_st_is_tag_char'), $ch))
        );
        $retBad = $fn->appendBasicBlock('ret_bad');
        $scanNext = $fn->appendBasicBlock('scan_next');
        $scanInc = $fn->appendBasicBlock('scan_inc');
        $context->builder->branchIf($isStop, $scanDone, $scanNext);
        $context->builder->positionAtEnd($scanNext);
        $context->builder->branchIf($bad, $retBad, $scanInc);
        $context->builder->positionAtEnd($scanInc);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($retBad);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($scanDone);
        $start = $context->builder->load($startSlot);
        $endI = $context->builder->load($iSlot);
        $nameLen = $context->builder->sub($endI, $start);
        $empty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $tooBig = $context->builder->icmp(Builder::INT_UGE, $nameLen, $outCap);
        $badLen = $context->builder->or($empty, $tooBig);
        $copy = $fn->appendBasicBlock('copy');
        $context->builder->branchIf($badLen, $ret0, $copy);

        $context->builder->positionAtEnd($copy);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($context->builder->inBoundsGEP($content, $start)),
            $nameLen
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $nameLen));
        $context->builder->call($context->lookupFunction('__phpc_st_tolower_buf'), $out, $nameLen);
        $context->builder->returnValue($i32->constInt(1, false));

        $context->builder->positionAtEnd($ret0);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function emitTagAllowed(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $i32->constInt(1, false);
        $zero = $i32->constInt(0, false);
        $tagStride = $sizeT->constInt(self::TAG_NAME_CAP, false);

        $name = $fn->getParam(0);
        $allowed = $fn->getParam(1);
        $allowedCount = $fn->getParam(2);

        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero, $iSlot);
        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $cont = $context->builder->icmp(Builder::INT_SLT, $i, $allowedCount);
        $context->builder->branchIf($cont, $body, $done);

        $context->builder->positionAtEnd($body);
        $offset = $context->builder->mul($context->builder->zExt($i, $sizeT), $tagStride);
        $cand = $context->builder->inBoundsGEP($allowed, $offset);
        $cmp = $context->builder->call($context->lookupFunction('strcmp'), $name, $cand);
        $matched = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
        $ret1 = $fn->appendBasicBlock('ret1');
        $next = $fn->appendBasicBlock('next');
        $context->builder->branchIf($matched, $ret1, $next);
        $context->builder->positionAtEnd($ret1);
        $context->builder->returnValue($one);
        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($zero);
    }

    private static function emitParseAllowed(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $allowed = $fn->getParam(0);
        $alen = $fn->getParam(1);
        $tags = $fn->getParam(2);
        $maxTags = $fn->getParam(3);

        $contentSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PARSE_CONTENT_CAP));
        $content = $context->builder->pointerCast($contentSlot, $context->getTypeFromString('int8*'));
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $context->builder->store($zeroI32, $countSlot);

        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->load($countSlot);
        $cont = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $i, $alen),
            $context->builder->icmp(Builder::INT_SLT, $count, $maxTags)
        );
        $context->builder->branchIf($cont, $body, $done);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($allowed, $i));
        $isLt = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('<'), false));
        $advance = $fn->appendBasicBlock('advance');
        $haveLt = $fn->appendBasicBlock('have_lt');
        $context->builder->branchIf($isLt, $haveLt, $advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($haveLt);
        $gt = $context->builder->call(
            $context->lookupFunction('__phpc_st_find_substr'),
            $allowed,
            $alen,
            self::literalCstr($context, '>'),
            $sizeT->constInt(1, false),
            $context->builder->add($i, $one)
        );
        $hasGt = $context->builder->icmp(Builder::INT_SGE, $gt, $zeroI32);
        $afterGt = $fn->appendBasicBlock('after_gt');
        $context->builder->branchIf($hasGt, $afterGt, $done);

        $context->builder->positionAtEnd($afterGt);
        $gtSize = $context->builder->zExt($gt, $sizeT);
        $rawLen = $context->builder->sub($context->builder->sub($gtSize, $i), $one);
        $maxCopy = $sizeT->constInt(self::PARSE_CONTENT_CAP - 1, false);
        $clen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $rawLen, $maxCopy),
            $maxCopy,
            $rawLen
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($content),
            $context->bytePtr($context->builder->inBoundsGEP($allowed, $context->builder->add($i, $one))),
            $clen
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($content, $clen));

        $count = $context->builder->load($countSlot);
        $tagPtr = $context->builder->inBoundsGEP(
            $tags,
            $context->builder->mul(
                $context->builder->zExt($count, $sizeT),
                $sizeT->constInt(self::TAG_NAME_CAP, false)
            )
        );
        $ok = $context->builder->call(
            $context->lookupFunction('__phpc_st_extract_tag_name'),
            $content,
            $clen,
            $tagPtr,
            $sizeT->constInt(self::TAG_NAME_CAP, false)
        );
        $isOk = $context->builder->icmp(Builder::INT_NE, $ok, $zeroI32);
        $context->builder->store(
            $context->builder->select($isOk, $context->builder->add($count, $oneI32), $count),
            $countSlot
        );
        $context->builder->store($context->builder->add($gtSize, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($countSlot));
    }

    private static function emitStripTags(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');

        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);
        $three = $sizeT->constInt(3, false);
        $four = $sizeT->constInt(4, false);
        $zeroI32 = $i32->constInt(0, false);
        $nullI8 = $i8p->constNull();
        $nullStr = $strPtr->constNull();

        $input = $fn->getParam(0);
        $allowed = $fn->getParam(1);

        $src = self::stringData($context, $input);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $input);

        $allowedListSlot = BasicBlockHelper::entryAlloca(
            $context,
            $i8->arrayType(self::ALLOWED_TAG_CAP * self::TAG_NAME_CAP)
        );
        $allowedList = $context->builder->pointerCast($allowedListSlot, $i8p);
        $allowedCountSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zeroI32, $allowedCountSlot);

        $allowInit = $fn->appendBasicBlock('allow_init');
        $allowDone = $fn->appendBasicBlock('allow_done');
        $allowNull = $context->builder->icmp(Builder::INT_EQ, $allowed, $nullStr);
        $context->builder->branchIf($allowNull, $allowDone, $allowInit);

        $context->builder->positionAtEnd($allowInit);
        $allowSrc = self::stringData($context, $allowed);
        $alen = $context->builder->call($context->lookupFunction('__string__strlen'), $allowed);
        $hasAllowed = $context->builder->icmp(Builder::INT_UGT, $alen, $zero);
        $parseAllowed = $fn->appendBasicBlock('parse_allowed');
        $context->builder->branchIf($hasAllowed, $parseAllowed, $allowDone);

        $context->builder->positionAtEnd($parseAllowed);
        $parsedCount = $context->builder->call(
            $context->lookupFunction('__phpc_st_parse_allowed'),
            $allowSrc,
            $alen,
            $allowedList,
            $i32->constInt(self::ALLOWED_TAG_CAP, false)
        );
        $context->builder->store($parsedCount, $allowedCountSlot);
        $context->builder->branch($allowDone);

        $context->builder->positionAtEnd($allowDone);
        $outCap = $context->builder->add($slen, $one);
        $out = $context->builder->pointerCast($context->builder->call($context->lookupFunction('malloc'), $outCap), $i8p);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullI8);
        $oom = $fn->appendBasicBlock('oom');
        $work = $fn->appendBasicBlock('work');
        $context->builder->branchIf($outNull, $oom, $work);

        $context->builder->positionAtEnd($oom);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            self::literalCstr($context, '')
        );
        $context->builder->returnValue($empty);

        $context->builder->positionAtEnd($work);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outCapSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $contentSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::CONTENT_CAP));
        $content = $context->builder->pointerCast($contentSlot, $i8p);
        $tagNameSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::TAG_NAME_CAP));
        $tagName = $context->builder->pointerCast($tagNameSlot, $i8p);
        $outSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $outLenSlot);
        $context->builder->store($outCap, $outCapSlot);
        $context->builder->store($out, $outSlot);

        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $cont = $context->builder->icmp(Builder::INT_ULT, $i, $slen);
        $context->builder->branchIf($cont, $body, $done);

        $context->builder->positionAtEnd($body);
        $srcI = $context->builder->inBoundsGEP($src, $i);
        $ch = $context->builder->load($srcI);
        $isLt = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('<'), false));
        $plain = $fn->appendBasicBlock('plain');
        $tagStart = $fn->appendBasicBlock('tag_start');
        $context->builder->branchIf($isLt, $tagStart, $plain);

        $context->builder->positionAtEnd($plain);
        $outPtr = $context->builder->load($outSlot);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($ch, $context->builder->inBoundsGEP($outPtr, $outLen));
        $context->builder->store($context->builder->add($outLen, $one), $outLenSlot);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($tagStart);
        $canComment = $context->builder->icmp(Builder::INT_ULT, $context->builder->add($i, $three), $slen);
        $commentCheck = $fn->appendBasicBlock('comment_check');
        $phpCheck = $fn->appendBasicBlock('php_check');
        $context->builder->branchIf($canComment, $commentCheck, $phpCheck);

        $context->builder->positionAtEnd($commentCheck);
        $isComment = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('memcmp'),
                $context->bytePtr($context->builder->inBoundsGEP($src, $i)),
                $context->bytePtr(self::literalCstr($context, '<!--')),
                $sizeT->constInt(4, false)
            ),
            $zeroI32
        );
        $commentBody = $fn->appendBasicBlock('comment_body');
        $context->builder->branchIf($isComment, $commentBody, $phpCheck);

        $context->builder->positionAtEnd($commentBody);
        $commentEnd = $context->builder->call(
            $context->lookupFunction('__phpc_st_find_substr'),
            $src,
            $slen,
            self::literalCstr($context, '-->'),
            $three,
            $context->builder->add($i, $four)
        );
        $hasCommentEnd = $context->builder->icmp(Builder::INT_SGE, $commentEnd, $zeroI32);
        $skipComment = $fn->appendBasicBlock('skip_comment');
        $context->builder->branchIf($hasCommentEnd, $skipComment, $phpCheck);
        $context->builder->positionAtEnd($skipComment);
        $context->builder->store($context->builder->add($context->builder->zExt($commentEnd, $sizeT), $three), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($phpCheck);
        $canPhp = $context->builder->icmp(Builder::INT_ULT, $context->builder->add($i, $one), $slen);
        $phpBody = $fn->appendBasicBlock('php_body');
        $tagBody = $fn->appendBasicBlock('tag_body');
        $context->builder->branchIf($canPhp, $phpBody, $tagBody);

        $context->builder->positionAtEnd($phpBody);
        $isPhp = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('memcmp'),
                $context->bytePtr($context->builder->inBoundsGEP($src, $i)),
                $context->bytePtr(self::literalCstr($context, '<?')),
                $two
            ),
            $zeroI32
        );
        $phpFind = $fn->appendBasicBlock('php_find');
        $context->builder->branchIf($isPhp, $phpFind, $tagBody);
        $context->builder->positionAtEnd($phpFind);
        $phpEnd = $context->builder->call(
            $context->lookupFunction('__phpc_st_find_substr'),
            $src,
            $slen,
            self::literalCstr($context, '?>'),
            $two,
            $context->builder->add($i, $two)
        );
        $hasPhpEnd = $context->builder->icmp(Builder::INT_SGE, $phpEnd, $zeroI32);
        $skipPhp = $fn->appendBasicBlock('skip_php');
        $context->builder->branchIf($hasPhpEnd, $skipPhp, $tagBody);
        $context->builder->positionAtEnd($skipPhp);
        $context->builder->store($context->builder->add($context->builder->zExt($phpEnd, $sizeT), $two), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($tagBody);
        $gt = $context->builder->call(
            $context->lookupFunction('__phpc_st_find_substr'),
            $src,
            $slen,
            self::literalCstr($context, '>'),
            $one,
            $context->builder->add($i, $one)
        );
        $hasGt = $context->builder->icmp(Builder::INT_SGE, $gt, $zeroI32);
        $noGt = $fn->appendBasicBlock('no_gt');
        $withGt = $fn->appendBasicBlock('with_gt');
        $context->builder->branchIf($hasGt, $withGt, $noGt);

        $context->builder->positionAtEnd($noGt);
        $outPtr = $context->builder->load($outSlot);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($ch, $context->builder->inBoundsGEP($outPtr, $outLen));
        $context->builder->store($context->builder->add($outLen, $one), $outLenSlot);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($withGt);
        $gtSize = $context->builder->zExt($gt, $sizeT);
        $clenRaw = $context->builder->sub($context->builder->sub($gtSize, $i), $one);
        $contentMax = $sizeT->constInt(self::CONTENT_CAP - 1, false);
        $clen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $clenRaw, $contentMax),
            $contentMax,
            $clenRaw
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($content),
            $context->bytePtr($context->builder->inBoundsGEP($src, $context->builder->add($i, $one))),
            $clen
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($content, $clen));
        $okName = $context->builder->call(
            $context->lookupFunction('__phpc_st_extract_tag_name'),
            $content,
            $clen,
            $tagName,
            $sizeT->constInt(self::TAG_NAME_CAP, false)
        );
        $allowedCount = $context->builder->load($allowedCountSlot);
        $nameOk = $context->builder->icmp(Builder::INT_NE, $okName, $zeroI32);
        $hasAllowedTags = $context->builder->icmp(Builder::INT_SGT, $allowedCount, $zeroI32);
        $allowCond = $context->builder->and($nameOk, $hasAllowedTags);
        $checkAllowed = $fn->appendBasicBlock('check_allowed');
        $afterCopy = $fn->appendBasicBlock('after_copy');
        $context->builder->branchIf($allowCond, $checkAllowed, $afterCopy);

        $context->builder->positionAtEnd($checkAllowed);
        $isAllowed = $context->builder->call(
            $context->lookupFunction('__phpc_st_tag_allowed'),
            $tagName,
            $allowedList,
            $allowedCount
        );
        $canCopyTag = $context->builder->icmp(Builder::INT_NE, $isAllowed, $zeroI32);
        $copyTag = $fn->appendBasicBlock('copy_tag');
        $context->builder->branchIf($canCopyTag, $copyTag, $afterCopy);

        $context->builder->positionAtEnd($copyTag);
        $tagLen = $context->builder->add($context->builder->sub($gtSize, $i), $one);
        $outCapCur = $context->builder->load($outCapSlot);
        $outLen = $context->builder->load($outLenSlot);
        $needGrow = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->add($outLen, $tagLen),
            $outCapCur
        );
        $grow = $fn->appendBasicBlock('grow');
        $copyNow = $fn->appendBasicBlock('copy_now');
        $context->builder->branchIf($needGrow, $grow, $copyNow);

        $context->builder->positionAtEnd($grow);
        $newCap = $context->builder->add($context->builder->mul($outCapCur, $sizeT->constInt(2, false)), $tagLen);
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($context->builder->load($outSlot)),
            $newCap
        );
        $growFail = $context->builder->icmp(Builder::INT_EQ, $grown, $nullI8);
        $growFailBb = $fn->appendBasicBlock('grow_fail');
        $growOkBb = $fn->appendBasicBlock('grow_ok');
        $context->builder->branchIf($growFail, $growFailBb, $growOkBb);

        $context->builder->positionAtEnd($growFailBb);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($outSlot));
        $empty2 = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            self::literalCstr($context, '')
        );
        $context->builder->returnValue($empty2);

        $context->builder->positionAtEnd($growOkBb);
        $context->builder->store($grown, $outSlot);
        $context->builder->store($newCap, $outCapSlot);
        $context->builder->branch($copyNow);

        $context->builder->positionAtEnd($copyNow);
        $outPtr = $context->builder->load($outSlot);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($context->builder->inBoundsGEP($outPtr, $outLen)),
            $context->bytePtr($context->builder->inBoundsGEP($src, $i)),
            $tagLen
        );
        $context->builder->store($context->builder->add($outLen, $tagLen), $outLenSlot);
        $context->builder->branch($afterCopy);

        $context->builder->positionAtEnd($afterCopy);
        $context->builder->store($context->builder->add($gtSize, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outPtr = $context->builder->load($outSlot);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outPtr, $outLen));
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($outLen, $i64),
            $outPtr
        );
        $context->builder->call($context->lookupFunction('free'), $outPtr);
        $context->builder->returnValue($result);
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
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

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
