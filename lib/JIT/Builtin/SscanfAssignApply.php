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
 * Apply sscanf assign blob to __value__** out pointers (#12467).
 */
final class SscanfAssignApply
{
    private const TAG_NULL = 0;

    private const TAG_LONG = 1;

    private const TAG_DOUBLE = 2;

    private const TAG_BOOL = 3;

    private const TAG_STRING = 4;

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_sscanf_apply_assign_blob');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_sscanf_apply_assign_blob', $probe);

            return;
        }

        self::ensureLibc($context);
        self::ensureValueWriters($context);
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $voidPtr = $context->getTypeFromString('void*');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType(
            $i64,
            false,
            $strPtr,
            $valuePtrPtr,
            $sizeT->pointerType(0)
        );
        $fn = $context->module->addFunction('phpc_sscanf_apply_assign_blob', $ft);

        $entry = $fn->appendBasicBlock('sscanf_apply_entry');
        $early = $fn->appendBasicBlock('sscanf_apply_early');
        $haveMeta = $fn->appendBasicBlock('sscanf_apply_have_meta');
        $loopHead = $fn->appendBasicBlock('sscanf_apply_loop');
        $loopBody = $fn->appendBasicBlock('sscanf_apply_body');
        $done = $fn->appendBasicBlock('sscanf_apply_done');

        $context->builder->positionAtEnd($entry);
        $meta = $fn->getParam(0);
        $outPtrs = $fn->getParam(1);
        $consumedOut = $fn->getParam(2);
        $nullMeta = $context->builder->icmp(Builder::INT_EQ, $meta, $strPtr->constNull());
        $context->builder->branchIf($nullMeta, $early, $haveMeta);

        $context->builder->positionAtEnd($early);
        $context->builder->store($sizeT->constInt(0, false), $consumedOut);
        $context->builder->returnValue($i64->constInt(0, false));

        $stringMap = $context->structFieldMap['__string__'];
        $context->builder->positionAtEnd($haveMeta);
        $metaLen = $context->builder->load($context->builder->structGep($meta, $stringMap['length']));
        $metaData = $context->builder->structGep($meta, $stringMap['value']);
        $tooShort = $context->builder->icmp(Builder::INT_ULT, $metaLen, $sizeT->constInt(16, false));
        $ready = $fn->appendBasicBlock('sscanf_apply_ready');
        $context->builder->branchIf($tooShort, $early, $ready);

        $context->builder->positionAtEnd($ready);
        $assignedSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $consumedSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($assignedSlot, $voidPtr),
            $context->builder->pointerCast($metaData, $voidPtr),
            $sizeT->constInt(8, false)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($consumedSlot, $voidPtr),
            $context->builder->pointerCast(
                $context->builder->gep($metaData, $sizeT->constInt(8, false)),
                $voidPtr
            ),
            $sizeT->constInt(8, false)
        );
        $context->builder->store(
            $context->builder->truncOrBitCast($context->builder->load($consumedSlot), $sizeT),
            $consumedOut
        );

        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(16, false), $posSlot);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $limit = $context->builder->load($assignedSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $limit),
            $loopBody,
            $done
        );

        $context->builder->positionAtEnd($loopBody);
        $pos = $context->builder->load($posSlot);
        $posGeLen = $context->builder->icmp(Builder::INT_UGE, $pos, $metaLen);
        $afterOne = $fn->appendBasicBlock('sscanf_apply_after_one');
        $readTag = $fn->appendBasicBlock('sscanf_apply_read_tag');
        $context->builder->branchIf($posGeLen, $done, $readTag);

        $context->builder->positionAtEnd($readTag);
        $tag = $context->builder->load($context->builder->pointerCast(
            $context->builder->gep($metaData, $pos),
            $i8->pointerType(0)
        ));
        $outVarPtr = $context->builder->load($context->builder->inBoundsGEP($outPtrs, $idx));
        self::emitApplyTag($context, $fn, $tag, $outVarPtr, $metaData, $posSlot, $afterOne);

        $context->builder->positionAtEnd($afterOne);
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($assignedSlot));
        $context->registerFunction('phpc_sscanf_apply_assign_blob', $fn);
    }

    private static function emitApplyTag(
        Context $context,
        LlvmFunction $fn,
        Value $tag,
        Value $outVarPtr,
        Value $metaData,
        Value $posSlot,
        BasicBlock $afterBb
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');

        $nullBb = $fn->appendBasicBlock('sscanf_apply_tag_null');
        $longBb = $fn->appendBasicBlock('sscanf_apply_tag_long');
        $doubleBb = $fn->appendBasicBlock('sscanf_apply_tag_double');
        $boolBb = $fn->appendBasicBlock('sscanf_apply_tag_bool');
        $stringBb = $fn->appendBasicBlock('sscanf_apply_tag_string');
        $defaultBb = $fn->appendBasicBlock('sscanf_apply_tag_default');

        $context->builder->switch_(
            $tag,
            $defaultBb,
            [
                $i8->constInt(self::TAG_NULL, false) => $nullBb,
                $i8->constInt(self::TAG_LONG, false) => $longBb,
                $i8->constInt(self::TAG_DOUBLE, false) => $doubleBb,
                $i8->constInt(self::TAG_BOOL, false) => $boolBb,
                $i8->constInt(self::TAG_STRING, false) => $stringBb,
            ]
        );

        $writeNull = $context->lookupFunction('__value__writeNull');
        $writeLong = $context->lookupFunction('__value__writeLong');
        $writeDouble = $context->lookupFunction('__value__writeDouble');
        $writeString = $context->lookupFunction('__value__writeString');
        $stringInit = $context->lookupFunction('__string__init');

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($writeNull, $outVarPtr);
        $context->builder->store($context->builder->add($context->builder->load($posSlot), $sizeT->constInt(1, false)), $posSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($longBb);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i64);
        self::readBytesAt($context, $metaData, $posSlot, 8, $valSlot);
        $context->builder->call($writeLong, $outVarPtr, $context->builder->load($valSlot));
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($doubleBb);
        $dblSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('double'));
        self::readBytesAt($context, $metaData, $posSlot, 8, $dblSlot);
        $context->builder->call($writeDouble, $outVarPtr, $context->builder->load($dblSlot));
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($boolBb);
        $boolSlot = BasicBlockHelper::entryAlloca($context, $i64);
        self::readBytesAt($context, $metaData, $posSlot, 8, $boolSlot);
        $context->builder->call($writeLong, $outVarPtr, $context->builder->load($boolSlot));
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($stringBb);
        $lenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        self::readBytesAt($context, $metaData, $posSlot, 8, $lenSlot);
        $slen = $context->builder->load($lenSlot);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($slen, $sizeT)
        );
        $pos = $context->builder->load($posSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $buf,
            $context->builder->pointerCast($context->builder->gep($metaData, $pos), $voidPtr),
            $context->builder->truncOrBitCast($slen, $sizeT)
        );
        $context->builder->store(
            $context->builder->add($pos, $context->builder->truncOrBitCast($slen, $sizeT)),
            $posSlot
        );
        $newStr = $context->builder->call($stringInit, $slen, $context->builder->pointerCast($buf, $i8p));
        $context->builder->call($writeString, $outVarPtr, $newStr);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->call($writeNull, $outVarPtr);
        $context->builder->branch($afterBb);
    }

    private static function readBytesAt(
        Context $context,
        Value $metaData,
        Value $posSlot,
        int $byteCount,
        Value $destSlot
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $pos = $context->builder->load($posSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($destSlot, $voidPtr),
            $context->builder->pointerCast($context->builder->gep($metaData, $pos), $voidPtr),
            $sizeT->constInt($byteCount, false)
        );
        $context->builder->store(
            $context->builder->add($pos, $sizeT->constInt($byteCount, false)),
            $posSlot
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
            ] as [$name, $ret, $params]
        ) {
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

    private static function ensureValueWriters(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__value__writeNull', $void, [$valuePtr]],
                ['__value__writeLong', $void, [$valuePtr, $i64]],
                ['__value__writeDouble', $void, [$valuePtr, $double]],
                ['__value__writeString', $void, [$valuePtr, $strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
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
}
