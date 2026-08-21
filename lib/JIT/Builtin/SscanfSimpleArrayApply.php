<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin-AOT sscanf for whitespace + %d/%s only (#33382 / #33389).
 *
 * NestedJIT VmSscanf aborts on libc-fgets {@see __string__init} inputs (#27663).
 *
 * ABIs:
 * - `phpc_sscanf_simple_array(line, specs) -> __hashtable__*` (array return, #33382)
 * - `phpc_sscanf_simple_assign(line, specs, outCount, outPtrs) -> int64` (by-ref, #33389)
 * where `$specs` is a string of `d`/`s` characters (e.g. `"ds"` for `%d %s`).
 */
final class SscanfSimpleArrayApply
{
    private const ABI = 'phpc_sscanf_simple_array';

    private const ASSIGN_ABI = 'phpc_sscanf_simple_assign';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
        } else {
            LibcExtern::ensureStrtolDecl($context);
            self::ensureDecls($context);
            self::emitBody($context, $probe);
        }
        self::ensureAssignLinked($context);
    }

    public static function ensureAssignLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ASSIGN_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ASSIGN_ABI, $probe);

            return;
        }
        LibcExtern::ensureStrtolDecl($context);
        self::ensureDecls($context);
        self::ensureAssignDecls($context);
        self::emitAssignBody($context, $probe);
    }

    /** @param list<'d'|'s'> $specs */
    public static function invoke(Context $context, Value $line, array $specs): Value
    {
        $specsStr = $context->builder->load(
            $context->constantStringFromString(\implode('', $specs))
        );

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $line,
            $specsStr
        );
    }

    /**
     * By-ref assign into `$outPtrs`; returns assigned count (#33389).
     *
     * @param list<'d'|'s'> $specs
     */
    public static function invokeAssign(
        Context $context,
        Value $line,
        array $specs,
        Value $outCount,
        Value $outPtrs
    ): Value {
        $specsStr = $context->builder->load(
            $context->constantStringFromString(\implode('', $specs))
        );

        return $context->builder->call(
            $context->lookupFunction(self::ASSIGN_ABI),
            $line,
            $specsStr,
            $outCount,
            $outPtrs
        );
    }

    private static function ensureDecls(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setLongAt', $void, [$htPtr, $i64, $i64]],
            ['__hashtable__setStringAt', $void, [$htPtr, $i64, $strPtr]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['__string__strlen', $i64, [$strPtr]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
        unset($sizeT);
    }

    private static function ensureAssignDecls(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        foreach ([
            ['__value__writeLong', $void, [$valuePtr, $i64]],
            ['__value__writeString', $void, [$valuePtr, $strPtr]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function emitBody(Context $context, ?LlvmFunction $existing): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($htPtr, false, $strPtr, $strPtr);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('ssa_entry');
        $fail = $fn->appendBasicBlock('ssa_fail');
        $ready = $fn->appendBasicBlock('ssa_ready');
        $loop = $fn->appendBasicBlock('ssa_loop');
        $body = $fn->appendBasicBlock('ssa_body');
        $skipWs = $fn->appendBasicBlock('ssa_skip_ws');
        $afterWs = $fn->appendBasicBlock('ssa_after_ws');
        $advWs = $fn->appendBasicBlock('ssa_adv_ws');
        $dispatch = $fn->appendBasicBlock('ssa_dispatch');
        $doD = $fn->appendBasicBlock('ssa_do_d');
        $storeD = $fn->appendBasicBlock('ssa_store_d');
        $doS = $fn->appendBasicBlock('ssa_do_s');
        $tokLoop = $fn->appendBasicBlock('ssa_tok_loop');
        $tokAdv = $fn->appendBasicBlock('ssa_tok_adv');
        $tokDone = $fn->appendBasicBlock('ssa_tok_done');
        $storeS = $fn->appendBasicBlock('ssa_store_s');
        $next = $fn->appendBasicBlock('ssa_next');
        $done = $fn->appendBasicBlock('ssa_done');

        $context->builder->positionAtEnd($entry);
        $line = $fn->getParam(0);
        $specs = $fn->getParam(1);
        $nullLine = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $nullSpecs = $context->builder->icmp(Builder::INT_EQ, $specs, $strPtr->constNull());
        $context->builder->branchIf($context->builder->or($nullLine, $nullSpecs), $fail, $ready);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $stringMap = $context->structFieldMap['__string__'];
        $context->builder->positionAtEnd($ready);
        $cursorSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $idxSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $endPtrSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $tokEndSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $htSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $htPtr);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($ht, $htSlot);
        $lineData = $context->builder->pointerCast(
            $context->builder->structGep($line, $stringMap['value']),
            $i8p
        );
        $context->builder->store($lineData, $cursorSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $nSpecs = $context->builder->call($context->lookupFunction('__string__strlen'), $specs);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $nSpecs),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($skipWs);
        $cur = $context->builder->load($cursorSlot);
        $ch = $context->builder->load($context->builder->pointerCast($cur, $i8->pointerType(0)));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $isSpace = self::emitIsSpace($context, $ch, $i8);
        $context->builder->branchIf($isNul, $done, $afterWs);

        $context->builder->positionAtEnd($afterWs);
        $context->builder->branchIf($isSpace, $advWs, $dispatch);

        $context->builder->positionAtEnd($advWs);
        $context->builder->store(
            $context->builder->gep($cur, $sizeT->constInt(1, false)),
            $cursorSlot
        );
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($dispatch);
        $specsData = $context->builder->pointerCast(
            $context->builder->structGep($specs, $stringMap['value']),
            $i8p
        );
        $specPtr = $context->builder->gep($specsData, $context->builder->intCast($idx, $sizeT));
        $specCh = $context->builder->load($context->builder->pointerCast($specPtr, $i8->pointerType(0)));
        $isD = $context->builder->icmp(Builder::INT_EQ, $specCh, $i8->constInt(\ord('d'), false));
        $context->builder->branchIf($isD, $doD, $doS);

        $context->builder->positionAtEnd($doD);
        $curD = $context->builder->load($cursorSlot);
        $val = $context->builder->call(
            $context->lookupFunction('strtol'),
            $curD,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $noProgress = $context->builder->icmp(Builder::INT_EQ, $endPtr, $curD);
        $context->builder->branchIf($noProgress, $done, $storeD);

        $context->builder->positionAtEnd($storeD);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $context->builder->load($htSlot),
            $idx,
            $val
        );
        $context->builder->store($endPtr, $cursorSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($doS);
        $start = $context->builder->load($cursorSlot);
        $context->builder->store($start, $tokEndSlot);
        $context->builder->branch($tokLoop);

        $context->builder->positionAtEnd($tokLoop);
        $tp = $context->builder->load($tokEndSlot);
        $tch = $context->builder->load($context->builder->pointerCast($tp, $i8->pointerType(0)));
        $tNul = $context->builder->icmp(Builder::INT_EQ, $tch, $i8->constInt(0, false));
        $tSpace = self::emitIsSpace($context, $tch, $i8);
        $context->builder->branchIf($context->builder->or($tNul, $tSpace), $tokDone, $tokAdv);

        $context->builder->positionAtEnd($tokAdv);
        $context->builder->store(
            $context->builder->gep($tp, $sizeT->constInt(1, false)),
            $tokEndSlot
        );
        $context->builder->branch($tokLoop);

        $context->builder->positionAtEnd($tokDone);
        $endTok = $context->builder->load($tokEndSlot);
        $startI = $context->builder->ptrToInt($start, $i64);
        $endI = $context->builder->ptrToInt($endTok, $i64);
        $tokLen = $context->builder->sub($endI, $startI);
        $tokStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $tokLen,
            $start
        );
        $context->builder->branch($storeS);

        $context->builder->positionAtEnd($storeS);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $context->builder->load($htSlot),
            $idx,
            $tokStr
        );
        $context->builder->store($endTok, $cursorSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store(
            $context->builder->add($context->builder->load($idxSlot), $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($htSlot));
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitAssignBody(Context $context, ?LlvmFunction $existing): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($i64, false, $strPtr, $strPtr, $i64, $valuePtrPtr);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::ASSIGN_ABI, $ft);

        $entry = $fn->appendBasicBlock('ssa_as_entry');
        $fail = $fn->appendBasicBlock('ssa_as_fail');
        $ready = $fn->appendBasicBlock('ssa_as_ready');
        $loop = $fn->appendBasicBlock('ssa_as_loop');
        $body = $fn->appendBasicBlock('ssa_as_body');
        $skipWs = $fn->appendBasicBlock('ssa_as_skip_ws');
        $afterWs = $fn->appendBasicBlock('ssa_as_after_ws');
        $advWs = $fn->appendBasicBlock('ssa_as_adv_ws');
        $dispatch = $fn->appendBasicBlock('ssa_as_dispatch');
        $doD = $fn->appendBasicBlock('ssa_as_do_d');
        $storeD = $fn->appendBasicBlock('ssa_as_store_d');
        $doS = $fn->appendBasicBlock('ssa_as_do_s');
        $tokLoop = $fn->appendBasicBlock('ssa_as_tok_loop');
        $tokAdv = $fn->appendBasicBlock('ssa_as_tok_adv');
        $tokDone = $fn->appendBasicBlock('ssa_as_tok_done');
        $storeS = $fn->appendBasicBlock('ssa_as_store_s');
        $next = $fn->appendBasicBlock('ssa_as_next');
        $done = $fn->appendBasicBlock('ssa_as_done');

        $context->builder->positionAtEnd($entry);
        $line = $fn->getParam(0);
        $specs = $fn->getParam(1);
        $outCount = $fn->getParam(2);
        $outPtrs = $fn->getParam(3);
        $nullLine = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $nullSpecs = $context->builder->icmp(Builder::INT_EQ, $specs, $strPtr->constNull());
        $badCount = $context->builder->icmp(Builder::INT_SLE, $outCount, $i64->constInt(0, false));
        $context->builder->branchIf(
            $context->builder->or($nullLine, $context->builder->or($nullSpecs, $badCount)),
            $fail,
            $ready
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));

        $stringMap = $context->structFieldMap['__string__'];
        $context->builder->positionAtEnd($ready);
        $cursorSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $idxSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $endPtrSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $tokEndSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $lineData = $context->builder->pointerCast(
            $context->builder->structGep($line, $stringMap['value']),
            $i8p
        );
        $context->builder->store($lineData, $cursorSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $nSpecs = $context->builder->call($context->lookupFunction('__string__strlen'), $specs);
        $limit = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $outCount, $nSpecs),
            $outCount,
            $nSpecs
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $limit),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($skipWs);
        $cur = $context->builder->load($cursorSlot);
        $ch = $context->builder->load($context->builder->pointerCast($cur, $i8->pointerType(0)));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $isSpace = self::emitIsSpace($context, $ch, $i8);
        $context->builder->branchIf($isNul, $done, $afterWs);

        $context->builder->positionAtEnd($afterWs);
        $context->builder->branchIf($isSpace, $advWs, $dispatch);

        $context->builder->positionAtEnd($advWs);
        $context->builder->store(
            $context->builder->gep($cur, $sizeT->constInt(1, false)),
            $cursorSlot
        );
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($dispatch);
        $specsData = $context->builder->pointerCast(
            $context->builder->structGep($specs, $stringMap['value']),
            $i8p
        );
        $specPtr = $context->builder->gep($specsData, $context->builder->intCast($idx, $sizeT));
        $specCh = $context->builder->load($context->builder->pointerCast($specPtr, $i8->pointerType(0)));
        $isD = $context->builder->icmp(Builder::INT_EQ, $specCh, $i8->constInt(\ord('d'), false));
        $context->builder->branchIf($isD, $doD, $doS);

        $context->builder->positionAtEnd($doD);
        $curD = $context->builder->load($cursorSlot);
        $val = $context->builder->call(
            $context->lookupFunction('strtol'),
            $curD,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $noProgress = $context->builder->icmp(Builder::INT_EQ, $endPtr, $curD);
        $context->builder->branchIf($noProgress, $done, $storeD);

        $context->builder->positionAtEnd($storeD);
        $outVarPtr = $context->builder->load($context->builder->inBoundsGEP($outPtrs, $idx));
        $context->builder->call($context->lookupFunction('__value__writeLong'), $outVarPtr, $val);
        $context->builder->store($endPtr, $cursorSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($doS);
        $start = $context->builder->load($cursorSlot);
        $context->builder->store($start, $tokEndSlot);
        $context->builder->branch($tokLoop);

        $context->builder->positionAtEnd($tokLoop);
        $tp = $context->builder->load($tokEndSlot);
        $tch = $context->builder->load($context->builder->pointerCast($tp, $i8->pointerType(0)));
        $tNul = $context->builder->icmp(Builder::INT_EQ, $tch, $i8->constInt(0, false));
        $tSpace = self::emitIsSpace($context, $tch, $i8);
        $context->builder->branchIf($context->builder->or($tNul, $tSpace), $tokDone, $tokAdv);

        $context->builder->positionAtEnd($tokAdv);
        $context->builder->store(
            $context->builder->gep($tp, $sizeT->constInt(1, false)),
            $tokEndSlot
        );
        $context->builder->branch($tokLoop);

        $context->builder->positionAtEnd($tokDone);
        $endTok = $context->builder->load($tokEndSlot);
        $startI = $context->builder->ptrToInt($start, $i64);
        $endI = $context->builder->ptrToInt($endTok, $i64);
        $tokLen = $context->builder->sub($endI, $startI);
        $emptyTok = $context->builder->icmp(Builder::INT_EQ, $tokLen, $i64->constInt(0, false));
        $context->builder->branchIf($emptyTok, $done, $storeS);

        $context->builder->positionAtEnd($storeS);
        $tokStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $tokLen,
            $start
        );
        $outVarPtrS = $context->builder->load($context->builder->inBoundsGEP($outPtrs, $idx));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outVarPtrS,
            $tokStr
        );
        $context->builder->store($endTok, $cursorSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store(
            $context->builder->add($context->builder->load($idxSlot), $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($idxSlot));
        $context->registerFunction(self::ASSIGN_ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitIsSpace(Context $context, Value $ch, $i8): Value
    {
        return $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x20, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x09, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0a, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0d, false))
                )
            )
        );
    }
}
