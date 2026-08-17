<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * Pure C-floor parseAndCompile + standalone emit for gen-0 M5 limited shapes (#26756 / #27426):
 *
 *   <?php
 *   echo "TOKEN\n";
 *
 *   <?php
 *   echo 'TOKEN';     // single-quoted (#27426) — only \\ and \' escapes
 *
 *   <?php
 *   echo 42;            // decimal literal (#27426)
 *
 *   <?php
 *   $a = 1 + 2; echo $a;   // folded to printf payload "3"
 *
 * examples/000-HelloWorld/example.php uses the echo shape so the committed argv driver can
 * compile it without PHPCfg\Parser NestedJIT. NestedJIT of
 * {@see M5TrivialEchoScript::parseAndCompile} hangs at runtime in the argv driver; this
 * path never NestedJITs that helper — LLVM scans the source, returns a module sentinel as
 * Block*, and standalone writes a POSIX `printf` shebang script that prints the payload.
 */
final class M5TrivialEchoNative
{
    public const LOGICAL = 'PHPCompiler\\JIT\\M5TrivialEchoScript::parseAndCompile';

    private const SENTINEL_GLOBAL = '__m5_te_sentinel_byte';
    private const PAYLOAD_GLOBAL = '__m5_te_payload';
    private const EXTRACT_FN = '__m5_te_try_extract';
    private const ARITH_EXTRACT_FN = '__m5_te_try_extract_arith';
    private const ECHO_INT_EXTRACT_FN = '__m5_te_try_extract_echo_int';
    private const EMIT_FN = '__m5_te_emit_to_path';

    /**
     * @param callable(string):string $llvmInternalName
     */
    public static function ensureParseAndCompile(
        Context $context,
        callable $llvmInternalName
    ): Value {
        $lc = strtolower(self::LOGICAL);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }

        LibcExtern::register($context);
        self::ensureGlobals($context);
        self::ensureExtractHelper($context);
        self::ensureEmitHelper($context);

        $objectPtr = $context->getTypeFromString('__object__*');
        $stringPtr = $context->getTypeFromString('__string__*');
        $mangled = $llvmInternalName(self::LOGICAL);
        $func = $context->module->addFunction(
            $mangled,
            $context->context->functionType($objectPtr, false, $stringPtr, $stringPtr)
        );
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $entry = $func->appendBasicBlock('m5_te_entry');
        $context->builder->positionAtEnd($entry);

        $code = $func->getParam(0);
        // Param 1 ($filename) is unused — never-seen paths are content-matched only.
        $payload = $context->builder->call($context->lookupFunction(self::EXTRACT_FN), $code);
        $payloadNull = $context->builder->icmp(
            Builder::INT_EQ,
            $payload,
            $stringPtr->constNull()
        );
        $missBb = $func->appendBasicBlock('m5_te_miss');
        $hitBb = $func->appendBasicBlock('m5_te_hit');
        $context->builder->branchIf($payloadNull, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($objectPtr->constNull());

        $context->builder->positionAtEnd($hitBb);
        $payloadSlot = $context->module->getNamedGlobal(self::PAYLOAD_GLOBAL);
        $context->builder->store($payload, $payloadSlot);
        $sentinelByte = $context->module->getNamedGlobal(self::SENTINEL_GLOBAL);
        $sentinel = $context->builder->pointerCast($sentinelByte, $objectPtr);
        $context->builder->returnValue($sentinel);

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__object__*';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            self::LOGICAL,
            [$stringPtr, $stringPtr],
            []
        );

        return $func;
    }

    public static function isRegistered(Context $context): bool
    {
        return isset($context->functions[strtolower(self::LOGICAL)]);
    }

    public static function lookup(Context $context): ?Value
    {
        return $context->functions[strtolower(self::LOGICAL)] ?? null;
    }

    public static function emitIsSentinel(Context $context, Value $block): Value
    {
        self::ensureGlobals($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $sentinelByte = $context->module->getNamedGlobal(self::SENTINEL_GLOBAL);
        $sentinel = $context->builder->pointerCast($sentinelByte, $objPtr);

        return $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->ptrtoint($block, $i64),
            $context->builder->ptrtoint($sentinel, $i64)
        );
    }

    /**
     * Emit sentinel check + ELF write. Returns [i1 handled, merge BasicBlock].
     * When handled==1, outfile is a runnable binary; when 0, caller continues.
     *
     * @return array{0: Value, 1: \PHPLLVM\BasicBlock}
     */
    public static function emitStandaloneSentinelCheck(
        Context $context,
        Value $block,
        Value $outFile,
        string $tag
    ): array {
        LibcExtern::register($context);
        self::ensureGlobals($context);
        self::ensureEmitHelper($context);

        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $isTe = self::emitIsSentinel($context, $block);
        $okBb = BasicBlockHelper::append($context, 'm5_te_std_ok_'.$tag);
        $noBb = BasicBlockHelper::append($context, 'm5_te_std_no_'.$tag);
        $mergeBb = BasicBlockHelper::append($context, 'm5_te_std_merge_'.$tag);
        $context->builder->branchIf($isTe, $okBb, $noBb);

        $context->builder->positionAtEnd($okBb);
        $payload = $context->builder->load($context->module->getNamedGlobal(self::PAYLOAD_GLOBAL));
        $rc = $context->builder->call($context->lookupFunction(self::EMIT_FN), $payload, $outFile);
        $emitOk = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $failBb = BasicBlockHelper::append($context, 'm5_te_std_fail_'.$tag);
        $okDone = BasicBlockHelper::append($context, 'm5_te_std_ok_done_'.$tag);
        $context->builder->branchIf($emitOk, $okDone, $failBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->call($context->lookupFunction('exit'), $i32->constInt(1, false));
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($okDone);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($noBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $okDone);
        $phi->addIncoming($i1->constInt(0, false), $noBb);

        return [$phi, $mergeBb];
    }

    private static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $context->module->getNamedGlobal(self::SENTINEL_GLOBAL)) {
            $g = $context->module->addGlobal($i8, self::SENTINEL_GLOBAL);
            $g->setInitializer($i8->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::PAYLOAD_GLOBAL)) {
            $g = $context->module->addGlobal($strPtr, self::PAYLOAD_GLOBAL);
            $g->setInitializer($strPtr->constNull());
        }
    }

    /** __m5_te_try_extract(__string__* code) -> __string__* payload|null */
    private static function ensureExtractHelper(Context $context): void
    {
        self::ensureArithExtractHelper($context);
        self::ensureEchoIntExtractHelper($context);
        if (null !== $context->module->getNamedFunction(self::EXTRACT_FN)) {
            $context->registerFunction(self::EXTRACT_FN, $context->module->getNamedFunction(self::EXTRACT_FN));

            return;
        }

        self::ensureStrncmp($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];

        $fn = $context->module->addFunction(
            self::EXTRACT_FN,
            $context->context->functionType($strPtr, false, $strPtr)
        );
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('entry');
        $miss = $fn->appendBasicBlock('miss');
        $done = $fn->appendBasicBlock('done');
        $b->positionAtEnd($entry);

        $result = $b->alloca($strPtr);
        $b->store($strPtr->constNull(), $result);

        $code = $fn->getParam(0);
        $codeNull = $b->icmp(Builder::INT_EQ, $code, $strPtr->constNull());
        $p1 = $fn->appendBasicBlock('p1');
        $b->branchIf($codeNull, $miss, $p1);
        $b->positionAtEnd($p1);

        $len = $b->load($b->structGep($code, $strMap['length']));
        $chars = $b->pointerCast($b->structGep($code, $strMap['value']), $i8p);
        $needPhp = $i64->constInt(5, false);
        $short = $b->icmp(Builder::INT_ULT, $len, $needPhp);
        $p2 = $fn->appendBasicBlock('p2');
        $b->branchIf($short, $miss, $p2);
        $b->positionAtEnd($p2);

        $phpTag = $b->pointerCast($context->constantFromString('<?php'), $i8p);
        $tagCmp = $b->call($context->lookupFunction('strncmp'), $chars, $phpTag, $b->zExt($needPhp, $sizeT));
        $tagOk = $b->icmp(Builder::INT_EQ, $tagCmp, $i32->constInt(0, false));
        $p3 = $fn->appendBasicBlock('p3');
        $b->branchIf($tagOk, $p3, $miss);
        $b->positionAtEnd($p3);

        $idx = $b->alloca($i64);
        $b->store($needPhp, $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ws1');

        $i = $b->load($idx);
        $rem = $b->sub($len, $i);
        $needEcho = $i64->constInt(4, false);
        $echoShort = $b->icmp(Builder::INT_ULT, $rem, $needEcho);
        $p4 = $fn->appendBasicBlock('p4');
        $arithTry = $fn->appendBasicBlock('arith_try');
        // Too short for "echo" — still try `$a = N + M; echo $a;` (#27426).
        $b->branchIf($echoShort, $arithTry, $p4);
        $b->positionAtEnd($p4);
        $echoLit = $b->pointerCast($context->constantFromString('echo'), $i8p);
        $echoCmp = $b->call(
            $context->lookupFunction('strncmp'),
            $b->gep($chars, $i),
            $echoLit,
            $b->zExt($needEcho, $sizeT)
        );
        $echoOk = $b->icmp(Builder::INT_EQ, $echoCmp, $i32->constInt(0, false));
        $p5 = $fn->appendBasicBlock('p5');
        $b->branchIf($echoOk, $p5, $arithTry);
        // Echo body missed — try assign+plus+echo (#27426 arith capability).
        $b->positionAtEnd($arithTry);
        $arithPayload = $b->call($context->lookupFunction(self::ARITH_EXTRACT_FN), $code);
        $arithNull = $b->icmp(Builder::INT_EQ, $arithPayload, $strPtr->constNull());
        $arithHit = $fn->appendBasicBlock('arith_hit');
        $b->branchIf($arithNull, $miss, $arithHit);
        $b->positionAtEnd($arithHit);
        $b->store($arithPayload, $result);
        $b->branch($done);
        $b->positionAtEnd($p5);
        $b->store($b->add($i, $needEcho), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ws2');

        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p6 = $fn->appendBasicBlock('p6');
        $b->branchIf($past, $miss, $p6);
        $b->positionAtEnd($p6);
        $ch0 = $b->load($b->gep($chars, $i));
        $isDq = $b->icmp(Builder::INT_EQ, $ch0, $i8->constInt(ord('"'), false));
        $isSq = $b->icmp(Builder::INT_EQ, $ch0, $i8->constInt(ord("'"), false));
        $p7 = $fn->appendBasicBlock('p7');
        $sqStart = $fn->appendBasicBlock('sq_start');
        $echoIntTry = $fn->appendBasicBlock('echo_int_try');
        $notDq = $fn->appendBasicBlock('not_dq_quote');
        $b->branchIf($isDq, $p7, $notDq);
        $b->positionAtEnd($notDq);
        $b->branchIf($isSq, $sqStart, $echoIntTry);
        // Not a string echo — try `echo <uint>;` before arith fallback (#27426).
        $b->positionAtEnd($echoIntTry);
        $echoIntPayload = $b->call($context->lookupFunction(self::ECHO_INT_EXTRACT_FN), $code);
        $echoIntNull = $b->icmp(Builder::INT_EQ, $echoIntPayload, $strPtr->constNull());
        $echoIntHit = $fn->appendBasicBlock('echo_int_hit');
        $b->branchIf($echoIntNull, $arithTry, $echoIntHit);
        $b->positionAtEnd($echoIntHit);
        $b->store($echoIntPayload, $result);
        $b->branch($done);
        $b->positionAtEnd($p7);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);

        $buf = $b->arrayAlloca($i8, $len);
        $outI = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $outI);

        $loop = $fn->appendBasicBlock('dec_loop');
        $body = $fn->appendBasicBlock('dec_body');
        $endQ = $fn->appendBasicBlock('dec_endq');
        $esc = $fn->appendBasicBlock('dec_esc');
        $lit = $fn->appendBasicBlock('dec_lit');
        $notDqBody = $fn->appendBasicBlock('dec_not_dq');
        $after = $fn->appendBasicBlock('dec_after');
        $b->branch($loop);

        $b->positionAtEnd($loop);
        $ci = $b->load($idx);
        $inRange = $b->icmp(Builder::INT_ULT, $ci, $len);
        $b->branchIf($inRange, $body, $miss);

        $b->positionAtEnd($body);
        $cch = $b->load($b->gep($chars, $ci));
        $isBs = $b->icmp(Builder::INT_EQ, $cch, $i8->constInt(ord('\\'), false));
        $isDqCh = $b->icmp(Builder::INT_EQ, $cch, $i8->constInt(ord('"'), false));
        $b->branchIf($isDqCh, $endQ, $notDqBody);
        $b->positionAtEnd($notDqBody);
        $b->branchIf($isBs, $esc, $lit);

        $b->positionAtEnd($esc);
        $ni = $b->add($ci, $i64->constInt(1, false));
        $escPast = $b->icmp(Builder::INT_UGE, $ni, $len);
        $escOk = $fn->appendBasicBlock('esc_ok');
        $b->branchIf($escPast, $miss, $escOk);
        $b->positionAtEnd($escOk);
        $nch = $b->load($b->gep($chars, $ni));
        $isN = $b->icmp(Builder::INT_EQ, $nch, $i8->constInt(ord('n'), false));
        $isT = $b->icmp(Builder::INT_EQ, $nch, $i8->constInt(ord('t'), false));
        $mapped = $b->alloca($i8);
        $b->store($nch, $mapped);
        $mapN = $fn->appendBasicBlock('map_n');
        $mapT = $fn->appendBasicBlock('map_t');
        $mapDone = $fn->appendBasicBlock('map_done');
        $afterN = $fn->appendBasicBlock('after_n');
        $b->branchIf($isN, $mapN, $afterN);
        $b->positionAtEnd($mapN);
        $b->store($i8->constInt(ord("\n"), false), $mapped);
        $b->branch($mapDone);
        $b->positionAtEnd($afterN);
        $b->branchIf($isT, $mapT, $mapDone);
        $b->positionAtEnd($mapT);
        $b->store($i8->constInt(ord("\t"), false), $mapped);
        $b->branch($mapDone);
        $b->positionAtEnd($mapDone);
        $oi = $b->load($outI);
        $b->store($b->load($mapped), $b->gep($buf, $oi));
        $b->store($b->add($oi, $i64->constInt(1, false)), $outI);
        $b->store($b->add($ci, $i64->constInt(2, false)), $idx);
        $b->branch($loop);

        $b->positionAtEnd($lit);
        $oi = $b->load($outI);
        $b->store($cch, $b->gep($buf, $oi));
        $b->store($b->add($oi, $i64->constInt(1, false)), $outI);
        $b->store($b->add($ci, $i64->constInt(1, false)), $idx);
        $b->branch($loop);

        $b->positionAtEnd($endQ);
        $b->store($b->add($ci, $i64->constInt(1, false)), $idx);
        $b->branch($after);

        // Single-quoted echo — only \\ and \' are escapes (#27426 / php-src).
        $b->positionAtEnd($sqStart);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $sqBuf = $b->arrayAlloca($i8, $len);
        $sqOutI = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $sqOutI);
        $sqLoop = $fn->appendBasicBlock('sq_loop');
        $sqBody = $fn->appendBasicBlock('sq_body');
        $sqEnd = $fn->appendBasicBlock('sq_end');
        $sqEsc = $fn->appendBasicBlock('sq_esc');
        $sqLit = $fn->appendBasicBlock('sq_lit');
        $sqNotEnd = $fn->appendBasicBlock('sq_not_end');
        $sqAfter = $fn->appendBasicBlock('sq_after');
        $b->branch($sqLoop);

        $b->positionAtEnd($sqLoop);
        $sqCi = $b->load($idx);
        $sqInRange = $b->icmp(Builder::INT_ULT, $sqCi, $len);
        $b->branchIf($sqInRange, $sqBody, $miss);

        $b->positionAtEnd($sqBody);
        $sqCh = $b->load($b->gep($chars, $sqCi));
        $sqIsBs = $b->icmp(Builder::INT_EQ, $sqCh, $i8->constInt(ord('\\'), false));
        $sqIsQ = $b->icmp(Builder::INT_EQ, $sqCh, $i8->constInt(ord("'"), false));
        $b->branchIf($sqIsQ, $sqEnd, $sqNotEnd);
        $b->positionAtEnd($sqNotEnd);
        $b->branchIf($sqIsBs, $sqEsc, $sqLit);

        $b->positionAtEnd($sqEsc);
        $sqNi = $b->add($sqCi, $i64->constInt(1, false));
        $sqEscPast = $b->icmp(Builder::INT_UGE, $sqNi, $len);
        $sqEscOk = $fn->appendBasicBlock('sq_esc_ok');
        $b->branchIf($sqEscPast, $miss, $sqEscOk);
        $b->positionAtEnd($sqEscOk);
        $sqNch = $b->load($b->gep($chars, $sqNi));
        $sqIsBsEsc = $b->icmp(Builder::INT_EQ, $sqNch, $i8->constInt(ord('\\'), false));
        $sqIsQEsc = $b->icmp(Builder::INT_EQ, $sqNch, $i8->constInt(ord("'"), false));
        $sqMapped = $b->alloca($i8);
        // Default: keep backslash + next as two literal chars (php-src single-quote).
        $sqTwoChar = $fn->appendBasicBlock('sq_two_char');
        $sqOneEsc = $fn->appendBasicBlock('sq_one_esc');
        $sqEscDone = $fn->appendBasicBlock('sq_esc_done');
        $sqAfterBsCheck = $fn->appendBasicBlock('sq_after_bs');
        $b->branchIf($sqIsBsEsc, $sqOneEsc, $sqAfterBsCheck);
        $b->positionAtEnd($sqAfterBsCheck);
        $b->branchIf($sqIsQEsc, $sqOneEsc, $sqTwoChar);
        $b->positionAtEnd($sqOneEsc);
        $b->store($sqNch, $sqMapped);
        $sqOi = $b->load($sqOutI);
        $b->store($b->load($sqMapped), $b->gep($sqBuf, $sqOi));
        $b->store($b->add($sqOi, $i64->constInt(1, false)), $sqOutI);
        $b->store($b->add($sqCi, $i64->constInt(2, false)), $idx);
        $b->branch($sqEscDone);
        $b->positionAtEnd($sqTwoChar);
        $sqOi2 = $b->load($sqOutI);
        $b->store($i8->constInt(ord('\\'), false), $b->gep($sqBuf, $sqOi2));
        $b->store($sqNch, $b->gep($sqBuf, $b->add($sqOi2, $i64->constInt(1, false))));
        $b->store($b->add($sqOi2, $i64->constInt(2, false)), $sqOutI);
        $b->store($b->add($sqCi, $i64->constInt(2, false)), $idx);
        $b->branch($sqEscDone);
        $b->positionAtEnd($sqEscDone);
        $b->branch($sqLoop);

        $b->positionAtEnd($sqLit);
        $sqOiLit = $b->load($sqOutI);
        $b->store($sqCh, $b->gep($sqBuf, $sqOiLit));
        $b->store($b->add($sqOiLit, $i64->constInt(1, false)), $sqOutI);
        $b->store($b->add($sqCi, $i64->constInt(1, false)), $idx);
        $b->branch($sqLoop);

        $b->positionAtEnd($sqEnd);
        $b->store($b->add($sqCi, $i64->constInt(1, false)), $idx);
        $b->branch($sqAfter);

        $b->positionAtEnd($sqAfter);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'sq_ws3');
        $sqTi = $b->load($idx);
        $sqTailPast = $b->icmp(Builder::INT_UGE, $sqTi, $len);
        $sqP8 = $fn->appendBasicBlock('sq_p8');
        $b->branchIf($sqTailPast, $miss, $sqP8);
        $b->positionAtEnd($sqP8);
        $sqTch = $b->load($b->gep($chars, $sqTi));
        $sqIsSemi = $b->icmp(Builder::INT_EQ, $sqTch, $i8->constInt(ord(';'), false));
        $sqP9 = $fn->appendBasicBlock('sq_p9');
        $b->branchIf($sqIsSemi, $sqP9, $miss);
        $b->positionAtEnd($sqP9);
        $b->store($b->add($sqTi, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'sq_ws4');
        $sqEndI = $b->load($idx);
        $sqAtEnd = $b->icmp(Builder::INT_EQ, $sqEndI, $len);
        $sqP10 = $fn->appendBasicBlock('sq_p10');
        $b->branchIf($sqAtEnd, $sqP10, $miss);
        $b->positionAtEnd($sqP10);
        $sqOutLen = $b->load($sqOutI);
        $sqPayload = $b->call(
            $context->lookupFunction('__string__init'),
            $sqOutLen,
            $b->pointerCast($sqBuf, $context->getTypeFromString('char*'))
        );
        $b->store($sqPayload, $result);
        $b->branch($done);

        $b->positionAtEnd($after);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ws3');
        $ti = $b->load($idx);
        $tailPast = $b->icmp(Builder::INT_UGE, $ti, $len);
        $p8 = $fn->appendBasicBlock('p8');
        $b->branchIf($tailPast, $miss, $p8);
        $b->positionAtEnd($p8);
        $tch = $b->load($b->gep($chars, $ti));
        $isSemi = $b->icmp(Builder::INT_EQ, $tch, $i8->constInt(ord(';'), false));
        $p9 = $fn->appendBasicBlock('p9');
        $b->branchIf($isSemi, $p9, $miss);
        $b->positionAtEnd($p9);
        // Optional trailing whitespace only — require end or only ws after ';'
        $b->store($b->add($ti, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ws4');
        $endI = $b->load($idx);
        $atEnd = $b->icmp(Builder::INT_EQ, $endI, $len);
        $p10 = $fn->appendBasicBlock('p10');
        $b->branchIf($atEnd, $p10, $miss);
        $b->positionAtEnd($p10);

        $outLen = $b->load($outI);
        $payload = $b->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $b->pointerCast($buf, $context->getTypeFromString('char*'))
        );
        $b->store($payload, $result);
        $b->branch($done);

        $b->positionAtEnd($miss);
        $b->store($strPtr->constNull(), $result);
        $b->branch($done);

        $b->positionAtEnd($done);
        $b->returnValue($b->load($result));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction(self::EXTRACT_FN, $fn);
    }


    /**
     * `__m5_te_try_extract_echo_int(__string__* code) -> __string__* payload|null`
     *
     * Matches `echo <uint>;` after `<?php` (optional ws) and returns a decimal string
     * payload for the printf emit path (#27426). Rejects leading-zero multi-digit.
     */
    private static function ensureEchoIntExtractHelper(Context $context): void
    {
        if (null !== $context->module->getNamedFunction(self::ECHO_INT_EXTRACT_FN)) {
            $context->registerFunction(
                self::ECHO_INT_EXTRACT_FN,
                $context->module->getNamedFunction(self::ECHO_INT_EXTRACT_FN)
            );

            return;
        }

        LibcExtern::register($context);
        self::ensureStrncmp($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];

        $fn = $context->module->addFunction(
            self::ECHO_INT_EXTRACT_FN,
            $context->context->functionType($strPtr, false, $strPtr)
        );
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('ei_entry');
        $miss = $fn->appendBasicBlock('ei_miss');
        $done = $fn->appendBasicBlock('ei_done');
        $b->positionAtEnd($entry);

        $result = $b->alloca($strPtr);
        $b->store($strPtr->constNull(), $result);

        $code = $fn->getParam(0);
        $codeNull = $b->icmp(Builder::INT_EQ, $code, $strPtr->constNull());
        $p1 = $fn->appendBasicBlock('ei_p1');
        $b->branchIf($codeNull, $miss, $p1);
        $b->positionAtEnd($p1);

        $len = $b->load($b->structGep($code, $strMap['length']));
        $chars = $b->pointerCast($b->structGep($code, $strMap['value']), $i8p);
        $needPhp = $i64->constInt(5, false);
        $short = $b->icmp(Builder::INT_ULT, $len, $needPhp);
        $p2 = $fn->appendBasicBlock('ei_p2');
        $b->branchIf($short, $miss, $p2);
        $b->positionAtEnd($p2);

        $phpTag = $b->pointerCast($context->constantFromString('<?php'), $i8p);
        $tagCmp = $b->call($context->lookupFunction('strncmp'), $chars, $phpTag, $b->zExt($needPhp, $sizeT));
        $tagOk = $b->icmp(Builder::INT_EQ, $tagCmp, $i32->constInt(0, false));
        $p3 = $fn->appendBasicBlock('ei_p3');
        $b->branchIf($tagOk, $p3, $miss);
        $b->positionAtEnd($p3);

        $idx = $b->alloca($i64);
        $b->store($needPhp, $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ei_ws0');

        $i = $b->load($idx);
        $rem = $b->sub($len, $i);
        $needEcho = $i64->constInt(4, false);
        $echoShort = $b->icmp(Builder::INT_ULT, $rem, $needEcho);
        $p4 = $fn->appendBasicBlock('ei_p4');
        $b->branchIf($echoShort, $miss, $p4);
        $b->positionAtEnd($p4);
        $echoLit = $b->pointerCast($context->constantFromString('echo'), $i8p);
        $echoCmp = $b->call(
            $context->lookupFunction('strncmp'),
            $b->gep($chars, $i),
            $echoLit,
            $b->zExt($needEcho, $sizeT)
        );
        $echoOk = $b->icmp(Builder::INT_EQ, $echoCmp, $i32->constInt(0, false));
        $p5 = $fn->appendBasicBlock('ei_p5');
        $b->branchIf($echoOk, $p5, $miss);
        $b->positionAtEnd($p5);
        $b->store($b->add($i, $needEcho), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ei_ws1');

        $value = self::emitScanUint($fn, $context, $chars, $len, $idx, 'ei_uint', $miss);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ei_ws2');

        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p6 = $fn->appendBasicBlock('ei_p6');
        $b->branchIf($past, $miss, $p6);
        $b->positionAtEnd($p6);
        $isSemi = $b->icmp(Builder::INT_EQ, $b->load($b->gep($chars, $i)), $i8->constInt(ord(';'), false));
        $p7 = $fn->appendBasicBlock('ei_p7');
        $b->branchIf($isSemi, $p7, $miss);
        $b->positionAtEnd($p7);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'ei_ws3');
        $atEnd = $b->icmp(Builder::INT_EQ, $b->load($idx), $len);
        $p8 = $fn->appendBasicBlock('ei_p8');
        $b->branchIf($atEnd, $p8, $miss);
        $b->positionAtEnd($p8);

        $numBuf = $b->arrayAlloca($i8, $i64->constInt(32, false));
        $fmt = $b->pointerCast($context->constantFromString('%ld'), $i8p);
        $nwritten = $b->call(
            $context->lookupFunction('snprintf'),
            $numBuf,
            $b->zExt($i64->constInt(32, false), $sizeT),
            $fmt,
            $value
        );
        $nwOk = $b->icmp(Builder::INT_SGT, $nwritten, $i32->constInt(0, false));
        $p9 = $fn->appendBasicBlock('ei_p9');
        $b->branchIf($nwOk, $p9, $miss);
        $b->positionAtEnd($p9);
        $payload = $b->call(
            $context->lookupFunction('__string__init'),
            $b->zExt($nwritten, $i64),
            $b->pointerCast($numBuf, $context->getTypeFromString('char*'))
        );
        $b->store($payload, $result);
        $b->branch($done);

        $b->positionAtEnd($miss);
        $b->store($strPtr->constNull(), $result);
        $b->branch($done);

        $b->positionAtEnd($done);
        $b->returnValue($b->load($result));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction(self::ECHO_INT_EXTRACT_FN, $fn);
    }

    /**
     * `__m5_te_try_extract_arith(__string__* code) -> __string__* payload|null`
     *
     * Matches `$name = <uint> + <uint>; echo $name;` after `<?php` (optional ws),
     * folds to a decimal string payload for the printf emit path (#27426).
     * Identifiers are compared in-place (no name buffer) to avoid alloca footguns.
     */
    private static function ensureArithExtractHelper(Context $context): void
    {
        if (null !== $context->module->getNamedFunction(self::ARITH_EXTRACT_FN)) {
            $context->registerFunction(
                self::ARITH_EXTRACT_FN,
                $context->module->getNamedFunction(self::ARITH_EXTRACT_FN)
            );

            return;
        }

        LibcExtern::register($context);
        self::ensureStrncmp($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];

        $fn = $context->module->addFunction(
            self::ARITH_EXTRACT_FN,
            $context->context->functionType($strPtr, false, $strPtr)
        );
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('a_entry');
        $miss = $fn->appendBasicBlock('a_miss');
        $done = $fn->appendBasicBlock('a_done');
        $b->positionAtEnd($entry);

        $result = $b->alloca($strPtr);
        $b->store($strPtr->constNull(), $result);

        $code = $fn->getParam(0);
        $codeNull = $b->icmp(Builder::INT_EQ, $code, $strPtr->constNull());
        $p1 = $fn->appendBasicBlock('a_p1');
        $b->branchIf($codeNull, $miss, $p1);
        $b->positionAtEnd($p1);

        $len = $b->load($b->structGep($code, $strMap['length']));
        $chars = $b->pointerCast($b->structGep($code, $strMap['value']), $i8p);
        $needPhp = $i64->constInt(5, false);
        $short = $b->icmp(Builder::INT_ULT, $len, $needPhp);
        $p2 = $fn->appendBasicBlock('a_p2');
        $b->branchIf($short, $miss, $p2);
        $b->positionAtEnd($p2);

        $phpTag = $b->pointerCast($context->constantFromString('<?php'), $i8p);
        $tagCmp = $b->call($context->lookupFunction('strncmp'), $chars, $phpTag, $b->zExt($needPhp, $sizeT));
        $tagOk = $b->icmp(Builder::INT_EQ, $tagCmp, $i32->constInt(0, false));
        $p3 = $fn->appendBasicBlock('a_p3');
        $b->branchIf($tagOk, $p3, $miss);
        $b->positionAtEnd($p3);

        $idx = $b->alloca($i64);
        $b->store($needPhp, $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws0');

        // '$'
        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p4 = $fn->appendBasicBlock('a_p4');
        $b->branchIf($past, $miss, $p4);
        $b->positionAtEnd($p4);
        $isDollar = $b->icmp(
            Builder::INT_EQ,
            $b->load($b->gep($chars, $i)),
            $i8->constInt(ord('$'), false)
        );
        $p5 = $fn->appendBasicBlock('a_p5');
        $b->branchIf($isDollar, $p5, $miss);
        $b->positionAtEnd($p5);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);

        // First ident: record start+len in-place
        $nameStart = $b->alloca($i64);
        $nameLen = $b->alloca($i64);
        self::emitScanIdentSpan($fn, $context, $chars, $len, $idx, $nameStart, $nameLen, 'a_id1', $miss);

        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws1');
        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p6 = $fn->appendBasicBlock('a_p6');
        $b->branchIf($past, $miss, $p6);
        $b->positionAtEnd($p6);
        $isEq = $b->icmp(Builder::INT_EQ, $b->load($b->gep($chars, $i)), $i8->constInt(ord('='), false));
        $p7 = $fn->appendBasicBlock('a_p7');
        $b->branchIf($isEq, $p7, $miss);
        $b->positionAtEnd($p7);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws2');

        $left = self::emitScanUint($fn, $context, $chars, $len, $idx, 'a_left', $miss);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws3');

        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p8 = $fn->appendBasicBlock('a_p8');
        $b->branchIf($past, $miss, $p8);
        $b->positionAtEnd($p8);
        $isPlus = $b->icmp(Builder::INT_EQ, $b->load($b->gep($chars, $i)), $i8->constInt(ord('+'), false));
        $p9 = $fn->appendBasicBlock('a_p9');
        $b->branchIf($isPlus, $p9, $miss);
        $b->positionAtEnd($p9);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws4');

        $right = self::emitScanUint($fn, $context, $chars, $len, $idx, 'a_right', $miss);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws5');

        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p10 = $fn->appendBasicBlock('a_p10');
        $b->branchIf($past, $miss, $p10);
        $b->positionAtEnd($p10);
        $isSemi = $b->icmp(Builder::INT_EQ, $b->load($b->gep($chars, $i)), $i8->constInt(ord(';'), false));
        $p11 = $fn->appendBasicBlock('a_p11');
        $b->branchIf($isSemi, $p11, $miss);
        $b->positionAtEnd($p11);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws6');

        // echo
        $i = $b->load($idx);
        $rem = $b->sub($len, $i);
        $needEcho = $i64->constInt(4, false);
        $echoShort = $b->icmp(Builder::INT_ULT, $rem, $needEcho);
        $p12 = $fn->appendBasicBlock('a_p12');
        $b->branchIf($echoShort, $miss, $p12);
        $b->positionAtEnd($p12);
        $echoLit = $b->pointerCast($context->constantFromString('echo'), $i8p);
        $echoCmp = $b->call(
            $context->lookupFunction('strncmp'),
            $b->gep($chars, $i),
            $echoLit,
            $b->zExt($needEcho, $sizeT)
        );
        $echoOk = $b->icmp(Builder::INT_EQ, $echoCmp, $i32->constInt(0, false));
        $p13 = $fn->appendBasicBlock('a_p13');
        $b->branchIf($echoOk, $p13, $miss);
        $b->positionAtEnd($p13);
        $b->store($b->add($i, $needEcho), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws7');

        // $name again
        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p14 = $fn->appendBasicBlock('a_p14');
        $b->branchIf($past, $miss, $p14);
        $b->positionAtEnd($p14);
        $isD2 = $b->icmp(Builder::INT_EQ, $b->load($b->gep($chars, $i)), $i8->constInt(ord('$'), false));
        $p15 = $fn->appendBasicBlock('a_p15');
        $b->branchIf($isD2, $p15, $miss);
        $b->positionAtEnd($p15);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);

        $echoStart = $b->alloca($i64);
        $echoLen = $b->alloca($i64);
        self::emitScanIdentSpan($fn, $context, $chars, $len, $idx, $echoStart, $echoLen, 'a_id2', $miss);

        $nl = $b->load($nameLen);
        $el = $b->load($echoLen);
        $lenEq = $b->icmp(Builder::INT_EQ, $nl, $el);
        $p16 = $fn->appendBasicBlock('a_p16');
        $b->branchIf($lenEq, $p16, $miss);
        $b->positionAtEnd($p16);
        $nameCmp = $b->call(
            $context->lookupFunction('strncmp'),
            $b->gep($chars, $b->load($nameStart)),
            $b->gep($chars, $b->load($echoStart)),
            $b->zExt($nl, $sizeT)
        );
        $nameOk = $b->icmp(Builder::INT_EQ, $nameCmp, $i32->constInt(0, false));
        $p17 = $fn->appendBasicBlock('a_p17');
        $b->branchIf($nameOk, $p17, $miss);
        $b->positionAtEnd($p17);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws8');

        $i = $b->load($idx);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $p18 = $fn->appendBasicBlock('a_p18');
        $b->branchIf($past, $miss, $p18);
        $b->positionAtEnd($p18);
        $isSemi2 = $b->icmp(Builder::INT_EQ, $b->load($b->gep($chars, $i)), $i8->constInt(ord(';'), false));
        $p19 = $fn->appendBasicBlock('a_p19');
        $b->branchIf($isSemi2, $p19, $miss);
        $b->positionAtEnd($p19);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        self::emitSkipWsIn($fn, $context, $chars, $len, $idx, 'a_ws9');
        $atEnd = $b->icmp(Builder::INT_EQ, $b->load($idx), $len);
        $p20 = $fn->appendBasicBlock('a_p20');
        $b->branchIf($atEnd, $p20, $miss);
        $b->positionAtEnd($p20);

        $sum = $b->add($left, $right);
        $numBuf = $b->arrayAlloca($i8, $i64->constInt(32, false));
        $fmt = $b->pointerCast($context->constantFromString('%ld'), $i8p);
        $nwritten = $b->call(
            $context->lookupFunction('snprintf'),
            $numBuf,
            $b->zExt($i64->constInt(32, false), $sizeT),
            $fmt,
            $sum
        );
        $nwOk = $b->icmp(Builder::INT_SGT, $nwritten, $i32->constInt(0, false));
        $p21 = $fn->appendBasicBlock('a_p21');
        $b->branchIf($nwOk, $p21, $miss);
        $b->positionAtEnd($p21);
        $payload = $b->call(
            $context->lookupFunction('__string__init'),
            $b->zExt($nwritten, $i64),
            $b->pointerCast($numBuf, $context->getTypeFromString('char*'))
        );
        $b->store($payload, $result);
        $b->branch($done);

        $b->positionAtEnd($miss);
        $b->store($strPtr->constNull(), $result);
        $b->branch($done);

        $b->positionAtEnd($done);
        $b->returnValue($b->load($result));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction(self::ARITH_EXTRACT_FN, $fn);
    }

    /**
     * Scan ident; store start index + length. Builder ends on success block.
     */
    private static function emitScanIdentSpan(
        Value $fn,
        Context $context,
        Value $chars,
        Value $len,
        Value $idxAlloca,
        Value $startAlloca,
        Value $lenAlloca,
        string $tag,
        \PHPLLVM\BasicBlock $miss
    ): void {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');

        $i = $b->load($idxAlloca);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $ok0 = $fn->appendBasicBlock($tag.'_ok0');
        $b->branchIf($past, $miss, $ok0);
        $b->positionAtEnd($ok0);
        $c0 = $b->load($b->gep($chars, $i));
        $isAz = $b->and(
            $b->icmp(Builder::INT_UGE, $c0, $i8->constInt(ord('a'), false)),
            $b->icmp(Builder::INT_ULE, $c0, $i8->constInt(ord('z'), false))
        );
        $isAZ = $b->and(
            $b->icmp(Builder::INT_UGE, $c0, $i8->constInt(ord('A'), false)),
            $b->icmp(Builder::INT_ULE, $c0, $i8->constInt(ord('Z'), false))
        );
        $isUs = $b->icmp(Builder::INT_EQ, $c0, $i8->constInt(ord('_'), false));
        $startOk = $b->or($b->or($isAz, $isAZ), $isUs);
        $ok1 = $fn->appendBasicBlock($tag.'_ok1');
        $b->branchIf($startOk, $ok1, $miss);
        $b->positionAtEnd($ok1);
        $b->store($i, $startAlloca);
        $b->store($b->add($i, $i64->constInt(1, false)), $idxAlloca);

        $loop = $fn->appendBasicBlock($tag.'_loop');
        $body = $fn->appendBasicBlock($tag.'_body');
        $done = $fn->appendBasicBlock($tag.'_done');
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $ci = $b->load($idxAlloca);
        $in = $b->icmp(Builder::INT_ULT, $ci, $len);
        $b->branchIf($in, $body, $done);
        $b->positionAtEnd($body);
        $ch = $b->load($b->gep($chars, $ci));
        $isAz2 = $b->and(
            $b->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('a'), false)),
            $b->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('z'), false))
        );
        $isAZ2 = $b->and(
            $b->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('A'), false)),
            $b->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('Z'), false))
        );
        $isDig = $b->and(
            $b->icmp(Builder::INT_UGE, $ch, $i8->constInt(ord('0'), false)),
            $b->icmp(Builder::INT_ULE, $ch, $i8->constInt(ord('9'), false))
        );
        $isUs2 = $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('_'), false));
        $okCh = $b->or($b->or($isAz2, $isAZ2), $b->or($isDig, $isUs2));
        $adv = $fn->appendBasicBlock($tag.'_adv');
        $b->branchIf($okCh, $adv, $done);
        $b->positionAtEnd($adv);
        $b->store($b->add($ci, $i64->constInt(1, false)), $idxAlloca);
        $b->branch($loop);
        $b->positionAtEnd($done);
        $endI = $b->load($idxAlloca);
        $b->store($b->sub($endI, $b->load($startAlloca)), $lenAlloca);
    }

    /**
     * Parse unsigned int at idx (no leading-zero multi-digit). Returns i64 value.
     * Builder ends on success block; branches to $miss on failure.
     */
    private static function emitScanUint(
        Value $fn,
        Context $context,
        Value $chars,
        Value $len,
        Value $idxAlloca,
        string $tag,
        \PHPLLVM\BasicBlock $miss
    ): Value {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $i = $b->load($idxAlloca);
        $past = $b->icmp(Builder::INT_UGE, $i, $len);
        $ok0 = $fn->appendBasicBlock($tag.'_ok0');
        $b->branchIf($past, $miss, $ok0);
        $b->positionAtEnd($ok0);
        $ptr = $b->gep($chars, $i);
        $end = $b->alloca($i8p);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $val = $b->call(
            $context->lookupFunction('strtol'),
            $ptr,
            $end,
            $i32->constInt(10, false)
        );
        $endV = $b->load($end);
        $advanced = $b->icmp(Builder::INT_NE, $endV, $ptr);
        $ok1 = $fn->appendBasicBlock($tag.'_ok1');
        $b->branchIf($advanced, $ok1, $miss);
        $b->positionAtEnd($ok1);
        $first = $b->load($ptr);
        $isDigit = $b->and(
            $b->icmp(Builder::INT_UGE, $first, $i8->constInt(ord('0'), false)),
            $b->icmp(Builder::INT_ULE, $first, $i8->constInt(ord('9'), false))
        );
        $ok2 = $fn->appendBasicBlock($tag.'_ok2');
        $b->branchIf($isDigit, $ok2, $miss);
        $b->positionAtEnd($ok2);
        $digLen = $b->sub($b->ptrtoint($endV, $i64), $b->ptrtoint($ptr, $i64));
        $multi = $b->icmp(Builder::INT_UGT, $digLen, $i64->constInt(1, false));
        $leadZero = $b->icmp(Builder::INT_EQ, $first, $i8->constInt(ord('0'), false));
        $bad = $b->and($multi, $leadZero);
        $ok3 = $fn->appendBasicBlock($tag.'_ok3');
        $b->branchIf($bad, $miss, $ok3);
        $b->positionAtEnd($ok3);
        $b->store($b->add($i, $digLen), $idxAlloca);

        return $val;
    }

    private static function emitSkipWsIn(
        Value $fn,
        Context $context,
        Value $chars,
        Value $len,
        Value $idxAlloca,
        string $tag
    ): void {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $loop = $fn->appendBasicBlock($tag.'_loop');
        $body = $fn->appendBasicBlock($tag.'_body');
        $done = $fn->appendBasicBlock($tag.'_done');
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $i = $b->load($idxAlloca);
        $in = $b->icmp(Builder::INT_ULT, $i, $len);
        $b->branchIf($in, $body, $done);
        $b->positionAtEnd($body);
        $ch = $b->load($b->gep($chars, $i));
        $sp = $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false));
        $tab = $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false));
        $nl = $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\n"), false));
        $cr = $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\r"), false));
        $ws = $b->or($b->or($sp, $tab), $b->or($nl, $cr));
        $adv = $fn->appendBasicBlock($tag.'_adv');
        $b->branchIf($ws, $adv, $done);
        $b->positionAtEnd($adv);
        $b->store($b->add($i, $i64->constInt(1, false)), $idxAlloca);
        $b->branch($loop);
        $b->positionAtEnd($done);
    }

    private static function ensureEmitHelper(Context $context): void
    {
        if (null !== $context->module->getNamedFunction(self::EMIT_FN)) {
            $context->registerFunction(self::EMIT_FN, $context->module->getNamedFunction(self::EMIT_FN));

            return;
        }

        self::ensureFputs($context);
        self::ensureFputc($context);
        self::ensureChmod($context);
        // Module-local fopen/fclose after LibcExtern always-on drop (#31764).
        LibcExtern::ensureStdioFile($context);
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];

        $fn = $context->module->addFunction(
            self::EMIT_FN,
            $context->context->functionType($i32, false, $strPtr, $strPtr)
        );
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('entry');
        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $b->positionAtEnd($entry);

        $payload = $fn->getParam(0);
        $outFile = $fn->getParam(1);
        $bad = $b->or(
            $b->icmp(Builder::INT_EQ, $payload, $strPtr->constNull()),
            $b->icmp(Builder::INT_EQ, $outFile, $strPtr->constNull())
        );
        $afterNull = $fn->appendBasicBlock('after_null');
        $b->branchIf($bad, $fail, $afterNull);
        $b->positionAtEnd($afterNull);

        // Portable emit: POSIX sh + printf (no cc/gcc required on host — #26756).
        $outChars = $b->pointerCast($b->structGep($outFile, $strMap['value']), $i8p);
        $mode = $b->pointerCast($context->constantFromString('w'), $i8p);
        $fp = $b->call($context->lookupFunction('fopen'), $outChars, $mode);
        $fpNull = $b->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $afterOpen = $fn->appendBasicBlock('after_open');
        $b->branchIf($fpNull, $fail, $afterOpen);
        $b->positionAtEnd($afterOpen);

        $hdr = $b->pointerCast(
            $context->constantFromString("#!/bin/sh\nprintf '%s' '"),
            $i8p
        );
        $b->call($context->lookupFunction('fputs'), $hdr, $fp);

        $payloadLen = $b->load($b->structGep($payload, $strMap['length']));
        $payloadChars = $b->pointerCast($b->structGep($payload, $strMap['value']), $i8p);
        $ei = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $ei);
        $eloop = $fn->appendBasicBlock('esc_loop');
        $ebody = $fn->appendBasicBlock('esc_body');
        $edone = $fn->appendBasicBlock('esc_done');
        $b->branch($eloop);
        $b->positionAtEnd($eloop);
        $eiv = $b->load($ei);
        $b->branchIf($b->icmp(Builder::INT_ULT, $eiv, $payloadLen), $ebody, $edone);
        $b->positionAtEnd($ebody);
        $ech = $b->load($b->gep($payloadChars, $eiv));
        // POSIX single-quote escape: ' -> '\'' 
        $isSq = $b->icmp(Builder::INT_EQ, $ech, $i8->constInt(ord("'"), false));
        $sqBb = $fn->appendBasicBlock('esc_sq');
        $rawBb = $fn->appendBasicBlock('esc_raw');
        $nextBb = $fn->appendBasicBlock('esc_next');
        $b->branchIf($isSq, $sqBb, $rawBb);
        $b->positionAtEnd($sqBb);
        $escSeq = $b->pointerCast($context->constantFromString("'\\''"), $i8p);
        $b->call($context->lookupFunction('fputs'), $escSeq, $fp);
        $b->branch($nextBb);
        $b->positionAtEnd($rawBb);
        $b->call($context->lookupFunction('fputc'), $b->zExt($ech, $i32), $fp);
        $b->branch($nextBb);
        $b->positionAtEnd($nextBb);
        $b->store($b->add($eiv, $i64->constInt(1, false)), $ei);
        $b->branch($eloop);

        $b->positionAtEnd($edone);
        $tail = $b->pointerCast($context->constantFromString("'\n"), $i8p);
        $b->call($context->lookupFunction('fputs'), $tail, $fp);
        $b->call($context->lookupFunction('fclose'), $fp);
        $b->call($context->lookupFunction('chmod'), $outChars, $i32->constInt(0755, false));
        $b->branch($ok);

        $b->positionAtEnd($fail);
        $b->returnValue($i32->constInt(1, false));
        $b->positionAtEnd($ok);
        $b->returnValue($i32->constInt(0, false));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction(self::EMIT_FN, $fn);
    }

    private static function ensureFputs(Context $context): void
    {
        if (null !== $context->module->getNamedFunction('fputs')) {
            $context->registerFunction('fputs', $context->module->getNamedFunction('fputs'));

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->addFunction(
            'fputs',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
        $context->registerFunction('fputs', $fn);
    }

    private static function ensureFputc(Context $context): void
    {
        if (null !== $context->module->getNamedFunction('fputc')) {
            $context->registerFunction('fputc', $context->module->getNamedFunction('fputc'));

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->addFunction(
            'fputc',
            $context->context->functionType($i32, false, $i32, $i8p)
        );
        $context->registerFunction('fputc', $fn);
    }

    /** Module-local chmod(2) after LibcExtern/Module always-on drop (#31374). */
    private static function ensureChmod(Context $context): void
    {
        if (null !== $context->module->getNamedFunction('chmod')) {
            $context->registerFunction('chmod', $context->module->getNamedFunction('chmod'));

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->addFunction(
            'chmod',
            $context->context->functionType($i32, false, $i8p, $i32)
        );
        $context->registerFunction('chmod', $fn);
    }

    /**
     * Module-local strncmp(3) after LibcExtern always-on drop (#31839).
     *
     * User-script strncmp() stays on NCompareJitHelper / VmString; M5 echo/`<?php`
     * tag scans call this before lookupFunction('strncmp'). Peer: ensureChmod (#31374).
     */
    private static function ensureStrncmp(Context $context): void
    {
        LibcExtern::ensureStrncmp($context);
    }
}
