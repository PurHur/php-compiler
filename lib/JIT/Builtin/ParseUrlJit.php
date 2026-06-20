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
 * LLVM __phpc_parse_url_* (mirrors phpc_parse_url.c / VmString::parseUrl, #5913).
 */
final class ParseUrlJit
{
    private const PARTS_SIZE = 64;

    private const OFF_SCHEME = 0;

    private const OFF_HOST = 8;

    private const OFF_PORT = 16;

    private const OFF_HAS_PORT = 20;

    private const OFF_USER = 24;

    private const OFF_PASS = 32;

    private const OFF_PATH = 40;

    private const OFF_QUERY = 48;

    private const OFF_FRAGMENT = 56;

    private const AUTH_BUF_SIZE = 512;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        $probeComp = $context->module->getNamedFunction('__phpc_parse_url_component');
        if (null !== $probeComp && $probeComp->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_parse_url_component', $probeComp);
            $probeAssoc = $context->module->getNamedFunction('__phpc_parse_url_assoc');
            if (null !== $probeAssoc) {
                $context->registerFunction('__phpc_parse_url_assoc', $probeAssoc);
            }
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_parse_url_strdup0', self::emitStrdup0(...));
        self::implementIfMissing($context, '__phpc_parse_url_substr', self::emitSubstr(...));
        self::implementIfMissing($context, '__phpc_parse_url_cstr', self::emitCstr(...));
        self::implementIfMissing($context, '__phpc_parse_url_parts_init', self::emitPartsInit(...));
        self::implementIfMissing($context, '__phpc_parse_url_parts_free', self::emitPartsFree(...));
        self::implementIfMissing($context, '__phpc_parse_url_is_scheme_char', self::emitIsSchemeChar(...));
        self::implementIfMissing($context, '__phpc_parse_url_min_pos', self::emitMinPos(...));
        self::implementIfMissing($context, '__phpc_parse_url_min_pos3', self::emitMinPos3(...));
        self::implementIfMissing($context, '__phpc_parse_url_parse_parts', self::emitParseParts(...));
        self::implementIfMissing($context, '__phpc_parse_url_write_component', self::emitWriteComponent(...));
        self::implementIfMissing($context, '__phpc_parse_url_maybe_set_string', self::emitMaybeSetString(...));
        self::implementIfMissing($context, '__phpc_parse_url_component', self::emitComponent(...));
        self::implementIfMissing($context, '__phpc_parse_url_assoc', self::emitAssoc(...));

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
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');

        return match ($name) {
            '__phpc_parse_url_strdup0' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p)
            ),
            '__phpc_parse_url_substr' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $sizeT, $sizeT)
            ),
            '__phpc_parse_url_cstr' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_parse_url_parts_init',
            '__phpc_parse_url_parts_free' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr)
            ),
            '__phpc_parse_url_is_scheme_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_parse_url_min_pos' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i32, $i32)
            ),
            '__phpc_parse_url_min_pos3' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i32, $i32, $i32)
            ),
            '__phpc_parse_url_parse_parts' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $voidPtr)
            ),
            '__phpc_parse_url_write_component' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $i32, $voidPtr)
            ),
            '__phpc_parse_url_maybe_set_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p, $i8p)
            ),
            '__phpc_parse_url_component' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $strPtr, $i64, $valuePtr)
            ),
            '__phpc_parse_url_assoc' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $strPtr, $valuePtr)
            ),
            default => throw new \LogicException('Unknown parse_url JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal(
            $context,
            'memmove',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'strchr', $context->context->functionType($i8p, false, $i8p, $i32));
        self::ensureExternal($context, 'strrchr', $context->context->functionType($i8p, false, $i8p, $i32));
        self::ensureExternal(
            $context,
            'strncmp',
            $context->context->functionType($i32, false, $i8p, $i8p, $sizeT)
        );
        self::ensureExternal(
            $context,
            'strtol',
            $context->context->functionType($i64, false, $i8p, $i8pp, $i32)
        );
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
                ['__value__writeNull', $void, [$valuePtr]],
                ['__value__writeBool', $void, [$valuePtr, $i32]],
                ['__value__writeString', $void, [$valuePtr, $strPtr]],
                ['__value__writeLong', $void, [$valuePtr, $i64]],
                ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
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

    private static function emitStrdup0(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $src = $fn->getParam(0);
        $one = $i64->constInt(1, false);
        $nullRet = $fn->appendBasicBlock('dup_null');
        $work = $fn->appendBasicBlock('dup_work');
        $fail = $fn->appendBasicBlock('dup_fail');
        $ok = $fn->appendBasicBlock('dup_ok');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $i8p->constNull());
        $context->builder->branchIf($isNull, $nullRet, $work);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnValue(self::cstrLiteral($context, ''));

        $context->builder->positionAtEnd($work);
        $len = $context->builder->call($context->lookupFunction('strlen'), $src);
        $out = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($context->builder->add($len, $one), $sizeT)
        );
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $i8p->constNull());
        $context->builder->branchIf($outNull, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i8p->constNull());

        $context->builder->positionAtEnd($ok);
        $outPtr = $context->builder->pointerCast($out, $i8p);
        $context->intrinsic->memcpy($outPtr, $src, $len, false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outPtr, $len));
        $context->builder->returnValue($outPtr);
    }

    private static function emitSubstr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $src = $fn->getParam(0);
        $off = $fn->getParam(1);
        $n = $fn->getParam(2);
        $one = $i64->constInt(1, false);

        $len = $context->builder->call($context->lookupFunction('strlen'), $src);
        $offGe = $context->builder->icmp(Builder::INT_UGE, $off, $len);
        $emptyBb = $fn->appendBasicBlock('sub_empty');
        $calcBb = $fn->appendBasicBlock('sub_calc');
        $context->builder->branchIf($offGe, $emptyBb, $calcBb);

        $context->builder->positionAtEnd($emptyBb);
        $ret = $context->builder->call($context->lookupFunction('__phpc_parse_url_strdup0'), self::cstrLiteral($context, ''));
        $context->builder->returnValue($ret);

        $context->builder->positionAtEnd($calcBb);
        $remain = $context->builder->sub($len, $off);
        $nGt = $context->builder->icmp(Builder::INT_UGT, $context->builder->add($off, $n), $len);
        $useN = $context->builder->select($nGt, $remain, $n);
        $out = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($context->builder->add($useN, $one), $sizeT)
        );
        $fail = $fn->appendBasicBlock('sub_fail');
        $ok = $fn->appendBasicBlock('sub_ok');
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $i8p->constNull());
        $context->builder->branchIf($outNull, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i8p->constNull());

        $context->builder->positionAtEnd($ok);
        $outPtr = $context->builder->pointerCast($out, $i8p);
        $srcOff = $context->builder->inBoundsGEP($src, $off);
        $context->intrinsic->memcpy($outPtr, $srcOff, $useN, false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outPtr, $useN));
        $context->builder->returnValue($outPtr);
    }

    private static function emitCstr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $cstr = $fn->getParam(0);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $context->builder->returnValue($ret);
    }

    private static function emitPartsInit(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $parts = $fn->getParam(0);
        $null = $i8p->constNull();
        $zero = $i32->constInt(0, false);

        foreach ([self::OFF_SCHEME, self::OFF_HOST, self::OFF_USER, self::OFF_PASS, self::OFF_PATH, self::OFF_QUERY, self::OFF_FRAGMENT] as $off) {
            $context->builder->store($null, self::partsStrField($context, $parts, $off));
        }
        $context->builder->store($zero, self::partsPortField($context, $parts));
        $context->builder->store($zero, self::partsHasPortField($context, $parts));
        $context->builder->returnVoid();
    }

    private static function emitPartsFree(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $parts = $fn->getParam(0);
        $free = $context->lookupFunction('free');

        foreach ([self::OFF_SCHEME, self::OFF_HOST, self::OFF_USER, self::OFF_PASS, self::OFF_PATH, self::OFF_QUERY, self::OFF_FRAGMENT] as $off) {
            $context->builder->call($free, $context->builder->load(self::partsStrField($context, $parts, $off)));
        }

        $context->builder->call($context->lookupFunction('__phpc_parse_url_parts_init'), $parts);
        $context->builder->returnVoid();
    }

    private static function emitIsSchemeChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ch = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $ok = self::isSchemeChar($context, $ch);

        $context->builder->returnValue(
            $context->builder->zExt($ok, $i32)
        );
    }

    private static function emitMinPos(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $a = $fn->getParam(0);
        $b = $fn->getParam(1);
        $negOne = $i32->constInt(-1, true);

        $aNeg = $context->builder->icmp(Builder::INT_SLT, $a, $negOne->typeOf()->constInt(0, true));
        $bNeg = $context->builder->icmp(Builder::INT_SLT, $b, $negOne->typeOf()->constInt(0, true));
        $retB = $fn->appendBasicBlock('mp_b');
        $retA = $fn->appendBasicBlock('mp_a');
        $cmp = $fn->appendBasicBlock('mp_cmp');
        $context->builder->branchIf($aNeg, $retB, $retA);

        $context->builder->positionAtEnd($retA);
        $context->builder->branchIf($bNeg, $cmp, $retB);

        $context->builder->positionAtEnd($retB);
        $context->builder->returnValue($b);

        $context->builder->positionAtEnd($cmp);
        $lt = $context->builder->icmp(Builder::INT_SLT, $a, $b);
        $context->builder->returnValue($context->builder->select($lt, $a, $b));
    }

    private static function emitMinPos3(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ab = $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_min_pos'),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $ret = $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_min_pos'),
            $ab,
            $fn->getParam(2)
        );
        $context->builder->returnValue($ret);
    }

    private static function emitParseParts(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pp_entry');
        $context->builder->positionAtEnd($entry);

        $url = $fn->getParam(0);
        $parts = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $negOne32 = $i32->constInt(-1, true);
        $strdup = $context->lookupFunction('__phpc_parse_url_strdup0');
        $partsFree = $context->lookupFunction('__phpc_parse_url_parts_free');

        $failBb = $fn->appendBasicBlock('pp_fail');
        $okBb = $fn->appendBasicBlock('pp_ok');
        $tailBb = $fn->appendBasicBlock('pp_tail');
        $authFail = $fn->appendBasicBlock('pp_auth_fail');
        $tailFail = $fn->appendBasicBlock('pp_tail_fail');

        $context->builder->call($context->lookupFunction('__phpc_parse_url_parts_init'), $parts);
        $urlNull = $context->builder->icmp(Builder::INT_EQ, $url, $strPtr->constNull());
        $workBb = $fn->appendBasicBlock('pp_work');
        $context->builder->branchIf($urlNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $map = $context->structFieldMap['__string__'];
        $input = $context->builder->pointerCast($context->builder->structGep($url, $map['value']), $i8p);
        $len = $context->builder->trunc(
            $context->builder->load($context->builder->structGep($url, $map['length'])),
            $sizeT
        );
        $rest = $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_substr'),
            $input,
            $sizeT->constInt(0, false),
            $len
        );
        $afterInit = $fn->appendBasicBlock('pp_after_init');
        $restNull = $context->builder->icmp(Builder::INT_EQ, $rest, $i8p->constNull());
        $context->builder->branchIf($restNull, $failBb, $afterInit);

        $context->builder->positionAtEnd($afterInit);
        $restSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($rest, $restSlot);

        $schemeBb = $fn->appendBasicBlock('pp_scheme_try');
        $schemeRelEntry = $fn->appendBasicBlock('pp_scheme_rel_entry');
        $doAuth = $fn->appendBasicBlock('pp_do_auth');
        $lenGe2 = $context->builder->icmp(Builder::INT_UGE, $len, $sizeT->constInt(2, false));
        $alpha = self::isAlpha($context, $context->builder->load($rest));
        $context->builder->branchIf($context->builder->and($lenGe2, $alpha), $schemeBb, $schemeRelEntry);

        $context->builder->positionAtEnd($schemeRelEntry);
        $curRest = $context->builder->load($restSlot);
        $isSchemeRel = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $curRest,
                self::cstrLiteral($context, '//'),
                $sizeT->constInt(2, false)
            ),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($isSchemeRel, $doAuth, $tailBb);

        $context->builder->positionAtEnd($schemeBb);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $schemeLoop = $fn->appendBasicBlock('pp_scheme_loop');
        $schemeBody = $fn->appendBasicBlock('pp_scheme_body');
        $schemeAfter = $fn->appendBasicBlock('pp_scheme_after');
        $schemeBreak = $fn->appendBasicBlock('pp_scheme_break');
        $context->builder->branch($schemeLoop);

        $context->builder->positionAtEnd($schemeLoop);
        $cur = $context->builder->load($restSlot);
        $idx = $context->builder->load($iSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($cur, $idx));
        $exit = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(58, false))
        );
        $context->builder->branchIf($exit, $schemeAfter, $schemeBody);

        $schemeCont = $fn->appendBasicBlock('pp_scheme_cont');
        $context->builder->positionAtEnd($schemeBody);
        $cur = $context->builder->load($restSlot);
        $idx = $context->builder->load($iSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($cur, $idx));
        $context->builder->branchIf(self::isSchemeChar($context, $ch), $schemeCont, $schemeBreak);
        $context->builder->positionAtEnd($schemeCont);
        $context->builder->store($context->builder->addNoSignedWrap($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($schemeLoop);
        $context->builder->positionAtEnd($schemeBreak);
        $context->builder->branch($schemeAfter);

        $context->builder->positionAtEnd($schemeAfter);
        $cur = $context->builder->load($restSlot);
        $idx = $context->builder->load($iSlot);
        $colonPtr = $context->builder->inBoundsGEP($cur, $idx);
        $hasScheme = $fn->appendBasicBlock('pp_has_scheme');
        $noScheme = $fn->appendBasicBlock('pp_no_scheme');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($colonPtr), $i8->constInt(58, false)),
            $hasScheme,
            $noScheme
        );
        $context->builder->positionAtEnd($noScheme);
        $curRest = $context->builder->load($restSlot);
        $isSchemeRel = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $curRest,
                self::cstrLiteral($context, '//'),
                $sizeT->constInt(2, false)
            ),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($isSchemeRel, $doAuth, $tailBb);

        $context->builder->positionAtEnd($hasScheme);
        $context->builder->store($i8->constInt(0, false), $colonPtr);
        $context->builder->store($cur, self::partsStrField($context, $parts, self::OFF_SCHEME));
        $newRest = $context->builder->call($strdup, $context->builder->inBoundsGEP($colonPtr, $one));
        $schemeAuth = $fn->appendBasicBlock('pp_scheme_auth');
        $schemeFail = $fn->appendBasicBlock('pp_scheme_fail');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $newRest, $i8p->constNull()), $schemeFail, $schemeAuth);
        $context->builder->positionAtEnd($schemeFail);
        $context->builder->call($partsFree, $parts);
        $context->builder->returnValue($i32->constInt(-1, true));

        $context->builder->positionAtEnd($schemeAuth);
        $context->builder->store($newRest, $restSlot);
        $isAuth = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $newRest,
                self::cstrLiteral($context, '//'),
                $sizeT->constInt(2, false)
            ),
            $i32->constInt(0, false)
        );
        $noAuth = $fn->appendBasicBlock('pp_no_auth');
        $context->builder->branchIf($isAuth, $doAuth, $noAuth);
        $context->builder->positionAtEnd($noAuth);
        $context->builder->branch($tailBb);

        $context->builder->positionAtEnd($doAuth);
        $curRest = $context->builder->load($restSlot);
        $authority = $context->builder->inBoundsGEP($curRest, $two);
        $end = $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_min_pos3'),
            self::ptrDiffOrNeg($context, $context->builder->call($context->lookupFunction('strchr'), $authority, $i32->constInt(47, false)), $authority, $negOne32),
            self::ptrDiffOrNeg($context, $context->builder->call($context->lookupFunction('strchr'), $authority, $i32->constInt(63, false)), $authority, $negOne32),
            self::ptrDiffOrNeg($context, $context->builder->call($context->lookupFunction('strchr'), $authority, $i32->constInt(35, false)), $authority, $negOne32)
        );
        $authBuf = $context->builder->alloca($i8->arrayType(self::AUTH_BUF_SIZE), 1, 'auth_buf');
        $authBase = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($authBuf, $i64->constInt(0, false), $i64->constInt(0, false)),
            $i8p
        );
        $endNeg = $context->builder->icmp(Builder::INT_SLT, $end, $i32->constInt(0, true));
        $authLen = $context->builder->select(
            $context->builder->icmp(
                Builder::INT_UGE,
                $useLen = $context->builder->select(
                    $endNeg,
                    $context->builder->call($context->lookupFunction('strlen'), $authority),
                    $context->builder->zExt($end, $sizeT)
                ),
                $sizeT->constInt(self::AUTH_BUF_SIZE - 1, false)
            ),
            $sizeT->constInt(self::AUTH_BUF_SIZE - 1, false),
            $useLen
        );
        $context->intrinsic->memcpy($authBase, $authority, $authLen, false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($authBase, $authLen));

        $atSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $at = $context->builder->call($context->lookupFunction('strrchr'), $authBase, $i32->constInt(64, false));
        $hasAtBb = $fn->appendBasicBlock('pp_has_at');
        $noAtBb = $fn->appendBasicBlock('pp_no_at');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $at, $i8p->constNull()), $hasAtBb, $noAtBb);

        $userCheck = $fn->appendBasicBlock('pp_user_check');
        $context->builder->positionAtEnd($hasAtBb);
        $context->builder->store($at, $atSlot);
        $context->builder->store($i8->constInt(0, false), $at);
        $colon = $context->builder->call($context->lookupFunction('strchr'), $authBase, $i32->constInt(58, false));
        $hasPassBb = $fn->appendBasicBlock('pp_has_pass');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $colon, $i8p->constNull()), $hasPassBb, $userCheck);

        $context->builder->positionAtEnd($hasPassBb);
        $context->builder->store($i8->constInt(0, false), $colon);
        $pass = $context->builder->call($strdup, $context->builder->inBoundsGEP($colon, $one));
        $afterPass = $fn->appendBasicBlock('pp_after_pass');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $pass, $i8p->constNull()), $authFail, $afterPass);
        $context->builder->positionAtEnd($afterPass);
        $context->builder->store($pass, self::partsStrField($context, $parts, self::OFF_PASS));
        $context->builder->branch($userCheck);

        $context->builder->positionAtEnd($userCheck);
        $userNonEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($authBase), $i8->constInt(0, false));
        $setUser = $fn->appendBasicBlock('pp_set_user');
        $afterUser = $fn->appendBasicBlock('pp_after_user');
        $context->builder->branchIf($userNonEmpty, $setUser, $afterUser);
        $storeUser = $fn->appendBasicBlock('pp_store_user');
        $context->builder->positionAtEnd($setUser);
        $user = $context->builder->call($strdup, $authBase);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $user, $i8p->constNull()), $authFail, $storeUser);
        $context->builder->positionAtEnd($storeUser);
        $context->builder->store($user, self::partsStrField($context, $parts, self::OFF_USER));
        $context->builder->branch($afterUser);

        $context->builder->positionAtEnd($afterUser);
        $atVal = $context->builder->load($atSlot);
        $hasAtStored = $context->builder->icmp(Builder::INT_NE, $atVal, $i8p->constNull());
        $memmoveBb = $fn->appendBasicBlock('pp_memmove');
        $context->builder->branchIf($hasAtStored, $memmoveBb, $noAtBb);
        $context->builder->positionAtEnd($memmoveBb);
        $afterAtPtr = $context->builder->inBoundsGEP($atVal, $one);
        $tailLen = $context->builder->call($context->lookupFunction('strlen'), $afterAtPtr);
        $context->builder->call(
            $context->lookupFunction('memmove'),
            $authBase,
            $afterAtPtr,
            $context->builder->add($tailLen, $sizeT->constInt(1, false))
        );
        $context->builder->branch($noAtBb);

        $context->builder->positionAtEnd($noAtBb);
        $portSep = $context->builder->call($context->lookupFunction('strchr'), $authBase, $i32->constInt(58, false));
        $hasPortBb = $fn->appendBasicBlock('pp_has_port');
        $noPortBb = $fn->appendBasicBlock('pp_no_port');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $portSep, $i8p->constNull()), $hasPortBb, $noPortBb);
        $context->builder->positionAtEnd($hasPortBb);
        $context->builder->store($i8->constInt(0, false), $portSep);
        $portEndSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($i8p->constNull(), $portEndSlot);
        $parsedPort = $context->builder->trunc(
            $context->builder->call(
                $context->lookupFunction('strtol'),
                $context->builder->inBoundsGEP($portSep, $one),
                $portEndSlot,
                $i32->constInt(10, false)
            ),
            $i32
        );
        $portOkBb = $fn->appendBasicBlock('pp_port_ok');
        $portBadBb = $fn->appendBasicBlock('pp_port_bad');
        $context->builder->branchIf(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGT, $parsedPort, $i32->constInt(0, false)),
                $context->builder->icmp(Builder::INT_SLE, $parsedPort, $i32->constInt(65535, false))
            ),
            $portOkBb,
            $portBadBb
        );
        $context->builder->positionAtEnd($portBadBb);
        $context->builder->call($partsFree, $parts);
        $context->builder->returnValue($i32->constInt(-1, true));
        $context->builder->positionAtEnd($portOkBb);
        $context->builder->store($parsedPort, self::partsPortField($context, $parts));
        $one32 = $i32->constInt(1, false);
        $context->builder->store($one32, self::partsHasPortField($context, $parts));
        $context->builder->branch($noPortBb);

        $hostOkBb = $fn->appendBasicBlock('pp_host_ok');
        $hostEmptyBb = $fn->appendBasicBlock('pp_host_empty');
        $hostStoreBb = $fn->appendBasicBlock('pp_host_store');
        $context->builder->positionAtEnd($noPortBb);
        $host = $context->builder->call($strdup, $authBase);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $host, $i8p->constNull()), $authFail, $hostOkBb);
        $context->builder->positionAtEnd($hostOkBb);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($host), $i8->constInt(0, false)),
            $hostEmptyBb,
            $hostStoreBb
        );
        $context->builder->positionAtEnd($hostEmptyBb);
        $context->builder->call($context->lookupFunction('free'), $host);
        $context->builder->branch($authFail);
        $context->builder->positionAtEnd($hostStoreBb);
        $context->builder->store($host, self::partsStrField($context, $parts, self::OFF_HOST));
        $curRest = $context->builder->load($restSlot);
        $restEmptyBb = $fn->appendBasicBlock('pp_rest_empty');
        $restTailBb = $fn->appendBasicBlock('pp_rest_tail');
        $context->builder->branchIf($endNeg, $restEmptyBb, $restTailBb);

        $restFreeBb = $fn->appendBasicBlock('pp_rest_free');
        $context->builder->positionAtEnd($restTailBb);
        $tail = $context->builder->call($strdup, $context->builder->inBoundsGEP($authority, $context->builder->zExt($end, $i64)));
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $tail, $i8p->constNull()), $authFail, $restFreeBb);
        $context->builder->positionAtEnd($restFreeBb);
        $context->builder->call($context->lookupFunction('free'), $curRest);
        $context->builder->store($tail, $restSlot);
        $context->builder->branch($tailBb);

        $restEmptyFreeBb = $fn->appendBasicBlock('pp_rest_empty_free');
        $context->builder->positionAtEnd($restEmptyBb);
        $emptyRest = $context->builder->call($strdup, self::cstrLiteral($context, ''));
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $emptyRest, $i8p->constNull()), $authFail, $restEmptyFreeBb);
        $context->builder->positionAtEnd($restEmptyFreeBb);
        $context->builder->call($context->lookupFunction('free'), $curRest);
        $context->builder->store($emptyRest, $restSlot);
        $context->builder->branch($tailBb);

        $context->builder->positionAtEnd($tailBb);
        $curRest = $context->builder->load($restSlot);
        $noRestBb = $fn->appendBasicBlock('pp_no_rest');
        $hasRestBb = $fn->appendBasicBlock('pp_has_rest');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $curRest, $i8p->constNull()), $hasRestBb, $noRestBb);

        $afterFrag = $fn->appendBasicBlock('pp_after_frag');
        $noHashBb = $fn->appendBasicBlock('pp_no_hash');
        $hasHashBb = $fn->appendBasicBlock('pp_has_hash');
        $context->builder->positionAtEnd($hasRestBb);
        $hashPtr = $context->builder->call($context->lookupFunction('strchr'), $curRest, $i32->constInt(35, false));
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $hashPtr, $i8p->constNull()), $hasHashBb, $noHashBb);
        $storeFrag = $fn->appendBasicBlock('pp_store_frag');
        $context->builder->positionAtEnd($hasHashBb);
        $context->builder->store($i8->constInt(0, false), $hashPtr);
        $frag = $context->builder->call($strdup, $context->builder->inBoundsGEP($hashPtr, $one));
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $frag, $i8p->constNull()), $tailFail, $storeFrag);
        $context->builder->positionAtEnd($storeFrag);
        $context->builder->store($frag, self::partsStrField($context, $parts, self::OFF_FRAGMENT));
        $context->builder->branch($afterFrag);
        $context->builder->positionAtEnd($noHashBb);
        $context->builder->branch($afterFrag);

        $noQ = $fn->appendBasicBlock('pp_no_q');
        $hasQb = $fn->appendBasicBlock('pp_has_q');
        $context->builder->positionAtEnd($afterFrag);
        $curRest = $context->builder->load($restSlot);
        $qPtr = $context->builder->call($context->lookupFunction('strchr'), $curRest, $i32->constInt(63, false));
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $qPtr, $i8p->constNull()), $hasQb, $noQ);
        $context->builder->positionAtEnd($hasQb);
        $context->builder->store($i8->constInt(0, false), $qPtr);
        $query = $context->builder->call($strdup, $context->builder->inBoundsGEP($qPtr, $one));
        $afterQ = $fn->appendBasicBlock('pp_after_q');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $query, $i8p->constNull()), $tailFail, $afterQ);
        $context->builder->positionAtEnd($afterQ);
        $context->builder->store($query, self::partsStrField($context, $parts, self::OFF_QUERY));
        $context->builder->branch($noQ);

        $context->builder->positionAtEnd($noQ);
        $context->builder->store($context->builder->load($restSlot), self::partsStrField($context, $parts, self::OFF_PATH));
        $context->builder->branch($okBb);

        $context->builder->positionAtEnd($tailFail);
        $context->builder->call($partsFree, $parts);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($restSlot));
        $context->builder->returnValue($i32->constInt(-1, true));

        $noRestOk = $fn->appendBasicBlock('pp_no_rest_ok');
        $context->builder->positionAtEnd($noRestBb);
        $emptyPath = $context->builder->call($strdup, self::cstrLiteral($context, ''));
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $emptyPath, $i8p->constNull()), $failBb, $noRestOk);
        $context->builder->positionAtEnd($noRestOk);
        $context->builder->store($emptyPath, self::partsStrField($context, $parts, self::OFF_PATH));
        $context->builder->branch($okBb);

        $context->builder->positionAtEnd($authFail);
        $context->builder->call($partsFree, $parts);
        $context->builder->returnValue($i32->constInt(-1, true));

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(-1, true));
    }

    private static function emitWriteComponent(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('wc_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $component = $fn->getParam(1);
        $parts = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $writeNull = $context->lookupFunction('__value__writeNull');
        $writeString = $context->lookupFunction('__value__writeString');
        $writeLong = $context->lookupFunction('__value__writeLong');
        $cstr = $context->lookupFunction('__phpc_parse_url_cstr');

        $defaultBb = $fn->appendBasicBlock('wc_default');
        $doneBb = $fn->appendBasicBlock('wc_done');
        $blocks = [];
        foreach (['scheme', 'host', 'port', 'user', 'pass', 'path', 'query', 'fragment'] as $idx => $name) {
            $blocks[$idx] = $fn->appendBasicBlock('wc_'.$name);
        }

        $switch = $context->builder->branchSwitch($component, $defaultBb, 8);
        foreach ($blocks as $idx => $bb) {
            $switch->addCase($i32->constInt($idx, false), $bb);
        }

        $strFields = [
            0 => self::OFF_SCHEME,
            1 => self::OFF_HOST,
            3 => self::OFF_USER,
            4 => self::OFF_PASS,
            6 => self::OFF_QUERY,
            7 => self::OFF_FRAGMENT,
        ];
        foreach ($strFields as $idx => $off) {
            $context->builder->positionAtEnd($blocks[$idx]);
            $ptr = $context->builder->load(self::partsStrField($context, $parts, $off));
            $empty = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ptr, $i8p->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($ptr), $i8->constInt(0, false))
            );
            $writeBb = $fn->appendBasicBlock('wc_write_'.$idx);
            $context->builder->branchIf($empty, $defaultBb, $writeBb);
            $context->builder->positionAtEnd($writeBb);
            $context->builder->call($writeString, $out, $context->builder->call($cstr, $ptr));
            $context->builder->branch($doneBb);
        }

        $context->builder->positionAtEnd($blocks[2]);
        $hasPort = $context->builder->load(self::partsHasPortField($context, $parts));
        $portBad = $fn->appendBasicBlock('wc_port_bad');
        $portOk = $fn->appendBasicBlock('wc_port_ok');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $hasPort, $i32->constInt(0, false)), $portBad, $portOk);
        $context->builder->positionAtEnd($portBad);
        $context->builder->call($writeNull, $out);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($portOk);
        $port = $context->builder->load(self::partsPortField($context, $parts));
        $context->builder->call($writeLong, $out, $context->builder->zExt($port, $i64));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($blocks[5]);
        $pathPtr = $context->builder->load(self::partsStrField($context, $parts, self::OFF_PATH));
        $pathWriteBb = $fn->appendBasicBlock('wc_path_write');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pathPtr, $i8p->constNull()),
            $defaultBb,
            $pathWriteBb
        );
        $context->builder->positionAtEnd($pathWriteBb);
        $context->builder->call($writeString, $out, $context->builder->call($cstr, $pathPtr));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->call($writeNull, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitMaybeSetString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $value = $fn->getParam(2);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $skip = $fn->appendBasicBlock('mss_skip');
        $set = $fn->appendBasicBlock('mss_set');
        $empty = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $value, $i8p->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($value), $i8->constInt(0, false))
        );
        $context->builder->branchIf($empty, $skip, $set);

        $context->builder->positionAtEnd($set);
        $cstr = $context->lookupFunction('__phpc_parse_url_cstr');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->call($cstr, $key),
            $context->builder->call($cstr, $value)
        );
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
    }

    private static function emitComponent(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $url = $fn->getParam(0);
        $component = $fn->getParam(1);
        $out = $fn->getParam(2);
        $valuePtr = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');
        $nullOut = $fn->appendBasicBlock('puc_null_out');
        $work = $fn->appendBasicBlock('puc_work');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull()), $nullOut, $work);

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $parts = $context->builder->alloca($context->getTypeFromString('int8'), self::PARTS_SIZE, 'pu_parts');
        $partsVoid = $context->builder->pointerCast($parts, $context->getTypeFromString('void*'));
        $status = $context->builder->call($context->lookupFunction('__phpc_parse_url_parse_parts'), $url, $partsVoid);
        $fail = $fn->appendBasicBlock('puc_fail');
        $ok = $fn->appendBasicBlock('puc_ok');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $status, $i32->constInt(0, false)), $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($ok);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_write_component'),
            $out,
            $context->builder->trunc($component, $i32),
            $partsVoid
        );
        $context->builder->call($context->lookupFunction('__phpc_parse_url_parts_free'), $partsVoid);
        $context->builder->returnVoid();
    }

    private static function emitAssoc(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $url = $fn->getParam(0);
        $out = $fn->getParam(1);
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullOut = $fn->appendBasicBlock('pua_null_out');
        $work = $fn->appendBasicBlock('pua_work');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull()), $nullOut, $work);

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $parts = $context->builder->alloca($context->getTypeFromString('int8'), self::PARTS_SIZE, 'pu_parts');
        $partsVoid = $context->builder->pointerCast($parts, $context->getTypeFromString('void*'));
        $status = $context->builder->call($context->lookupFunction('__phpc_parse_url_parse_parts'), $url, $partsVoid);
        $fail = $fn->appendBasicBlock('pua_fail');
        $ok = $fn->appendBasicBlock('pua_ok');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $status, $i32->constInt(0, false)), $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($ok);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $fn->appendBasicBlock('pua_ht_fail');
        $fill = $fn->appendBasicBlock('pua_fill');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull()), $htNull, $fill);

        $context->builder->positionAtEnd($htNull);
        $context->builder->call($context->lookupFunction('__phpc_parse_url_parts_free'), $partsVoid);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->returnVoid();

        $maybe = $context->lookupFunction('__phpc_parse_url_maybe_set_string');
        $context->builder->positionAtEnd($fill);
        foreach ([
            ['scheme', self::OFF_SCHEME],
            ['host', self::OFF_HOST],
            ['user', self::OFF_USER],
            ['pass', self::OFF_PASS],
            ['path', self::OFF_PATH],
            ['query', self::OFF_QUERY],
            ['fragment', self::OFF_FRAGMENT],
        ] as [$key, $off]) {
            $ptr = $context->builder->load(self::partsStrField($context, $parts, $off));
            $context->builder->call($maybe, $ht, self::cstrLiteral($context, $key), $ptr);
        }
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->lookupFunction('__phpc_parse_url_cstr');
        $sizeT = $context->getTypeFromString('size_t');
        $urlEmptyPathBb = $fn->appendBasicBlock('pua_url_empty_path');
        $urlEmptyPathSkipBb = $fn->appendBasicBlock('pua_url_empty_path_skip');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->call($context->lookupFunction('strlen'), $url),
                $sizeT->constInt(0, false)
            ),
            $urlEmptyPathBb,
            $urlEmptyPathSkipBb
        );
        $context->builder->positionAtEnd($urlEmptyPathBb);
        $pathPtr = $context->builder->load(self::partsStrField($context, $parts, self::OFF_PATH));
        $pathPresentBb = $fn->appendBasicBlock('pua_path_present');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pathPtr, $i8p->constNull()),
            $urlEmptyPathSkipBb,
            $pathPresentBb
        );
        $context->builder->positionAtEnd($pathPresentBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->call($cstr, self::cstrLiteral($context, 'path')),
            $context->builder->call($cstr, $pathPtr)
        );
        $context->builder->branch($urlEmptyPathSkipBb);
        $context->builder->positionAtEnd($urlEmptyPathSkipBb);
        $port = $context->builder->load(self::partsPortField($context, $parts));
        $hasPort = $context->builder->load(self::partsHasPortField($context, $parts));
        $portPos = $fn->appendBasicBlock('pua_port');
        $doneFill = $fn->appendBasicBlock('pua_done_fill');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $hasPort, $i32->constInt(0, false)), $portPos, $doneFill);
        $context->builder->positionAtEnd($portPos);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->call($context->lookupFunction('__phpc_parse_url_cstr'), self::cstrLiteral($context, 'port')),
            $context->builder->zExt($port, $i64)
        );
        $context->builder->branch($doneFill);

        $context->builder->positionAtEnd($doneFill);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        $context->builder->call($context->lookupFunction('__phpc_parse_url_parts_free'), $partsVoid);
        $context->builder->returnVoid();
    }

    private static function partsStrField(Context $context, Value $parts, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast(
            $context->builder->gep($context->builder->pointerCast($parts, $i8p), $i64->constInt($offset, false)),
            $i8p->pointerType(0)
        );
    }

    private static function partsPortField(Context $context, Value $parts): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $context->getTypeFromString('int32')->pointerType(0);

        return $context->builder->pointerCast(
            $context->builder->gep($context->builder->pointerCast($parts, $i8p), $i64->constInt(self::OFF_PORT, false)),
            $i32p
        );
    }

    private static function partsHasPortField(Context $context, Value $parts): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $context->getTypeFromString('int32')->pointerType(0);

        return $context->builder->pointerCast(
            $context->builder->gep($context->builder->pointerCast($parts, $i8p), $i64->constInt(self::OFF_HAS_PORT, false)),
            $i32p
        );
    }

    private static function ptrDiffOrNeg(Context $context, Value $ptr, Value $base, Value $negOne): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $i8p->constNull());

        return $context->builder->select(
            $isNull,
            $negOne,
            $context->builder->trunc(
                $context->builder->sub($context->builder->ptrToInt($ptr, $i64), $context->builder->ptrToInt($base, $i64)),
                $i32
            )
        );
    }

    private static function isAlpha(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(65, false)),
                $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(90, false))
            ),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(97, false)),
                $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(122, false))
            )
        );
    }

    private static function isSchemeChar(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            self::isAlpha($context, $ch),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
                    $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
                ),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(43, false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(46, false)),
                        $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(45, false))
                    )
                )
            )
        );
    }

    private static function cstrLiteral(Context $context, string $text): Value
    {
        return $context->pointerFromStringConstant($text);
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

