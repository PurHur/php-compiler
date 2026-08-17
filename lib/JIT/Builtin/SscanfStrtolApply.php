<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin-AOT integer by-ref scan via libc strtol (#27663).
 *
 * NestedJIT parseAssignMeta aborts when the input comes from libc fgets → __string__init
 * (gdb: VmSscanf::parseWithConsumed → abort). Literal/concat NestedJIT is fine. This path
 * covers compile-time whitespace+%d formats without NestedJIT.
 */
final class SscanfStrtolApply
{
    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_sscanf_strtol_assign');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_sscanf_strtol_assign', $probe);

            return;
        }

        LibcExtern::register($context);
        self::ensureValueWriters($context);
        self::emitBody($context, null);
    }

    /** True when format is only whitespace + %d (optional l/h/z/t) / %% — no width/suppress. */
    public static function isStrtolOnlyFormat(string $format): bool
    {
        $len = \strlen($format);
        $i = 0;
        $specs = 0;
        while ($i < $len) {
            $ch = $format[$i];
            if ('%' === $ch) {
                if ($i + 1 >= $len) {
                    return false;
                }
                ++$i;
                if ('%' === $format[$i]) {
                    ++$i;
                    continue;
                }
                while ($i < $len && (\in_array($format[$i], ['l', 'h', 'z', 't'], true))) {
                    ++$i;
                }
                if ($i >= $len || 'd' !== $format[$i]) {
                    return false;
                }
                ++$i;
                ++$specs;
                continue;
            }
            if (\ctype_space($ch)) {
                ++$i;
                continue;
            }

            return false;
        }

        return $specs > 0;
    }

    private static function emitBody(Context $context, ?LlvmFunction $existing): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64, $valuePtrPtr);
        $fn = null !== $existing ? $existing : $context->module->addFunction('phpc_sscanf_strtol_assign', $ft);

        $entry = $fn->appendBasicBlock('strtol_entry');
        $fail = $fn->appendBasicBlock('strtol_fail');
        $ready = $fn->appendBasicBlock('strtol_ready');
        $loopHead = $fn->appendBasicBlock('strtol_loop');
        $loopBody = $fn->appendBasicBlock('strtol_body');
        $skipWs = $fn->appendBasicBlock('strtol_skip_ws');
        $afterWs = $fn->appendBasicBlock('strtol_after_ws');
        $doScan = $fn->appendBasicBlock('strtol_do_scan');
        $store = $fn->appendBasicBlock('strtol_store');
        $done = $fn->appendBasicBlock('strtol_done');
        $advanceWs = $fn->appendBasicBlock('strtol_adv_ws');

        $context->builder->positionAtEnd($entry);
        $line = $fn->getParam(0);
        $outCount = $fn->getParam(1);
        $outPtrs = $fn->getParam(2);
        $nullLine = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $zeroCount = $context->builder->icmp(Builder::INT_SLE, $outCount, $i64->constInt(0, false));
        $context->builder->branchIf($context->builder->or($nullLine, $zeroCount), $fail, $ready);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));

        $stringMap = $context->structFieldMap['__string__'];
        $context->builder->positionAtEnd($ready);
        $cursorSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $idxSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $endPtrSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $data = $context->builder->pointerCast(
            $context->builder->structGep($line, $stringMap['value']),
            $i8p
        );
        $context->builder->store($data, $cursorSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $outCount),
            $loopBody,
            $done
        );

        $context->builder->positionAtEnd($loopBody);
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($skipWs);
        $cur = $context->builder->load($cursorSlot);
        $ch = $context->builder->load($context->builder->pointerCast($cur, $i8->pointerType(0)));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $isSpace = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x20, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x09, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0a, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0d, false))
                )
            )
        );
        $context->builder->branchIf($isNul, $done, $afterWs);

        $context->builder->positionAtEnd($afterWs);
        $context->builder->branchIf($isSpace, $advanceWs, $doScan);

        $context->builder->positionAtEnd($advanceWs);
        $context->builder->store(
            $context->builder->gep($cur, $sizeT->constInt(1, false)),
            $cursorSlot
        );
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($doScan);
        $cur2 = $context->builder->load($cursorSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $val = $context->builder->call(
            $context->lookupFunction('strtol'),
            $cur2,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $noProgress = $context->builder->icmp(Builder::INT_EQ, $endPtr, $cur2);
        $context->builder->branchIf($noProgress, $done, $store);

        $context->builder->positionAtEnd($store);
        $outVarPtr = $context->builder->load($context->builder->inBoundsGEP($outPtrs, $idx));
        $context->builder->call($context->lookupFunction('__value__writeLong'), $outVarPtr, $val);
        $context->builder->store($endPtr, $cursorSlot);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($idxSlot));
        $context->registerFunction('phpc_sscanf_strtol_assign', $fn);
    }

    private static function ensureValueWriters(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        try {
            $context->lookupFunction('__value__writeLong');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                '__value__writeLong',
                $context->context->functionType($void, false, $valuePtr, $i64)
            );
            $context->registerFunction('__value__writeLong', $fn);
        }
    }
}
