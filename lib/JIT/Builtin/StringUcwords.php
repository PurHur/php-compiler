<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__ucwords / __string__ucwords_ex (VmString::asciiUcwords / asciiUcwordsEx).
 */
final class StringUcwords
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__ucwords');
        $entry = $fn->appendBasicBlock('ucwords_main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        self::transformInPlace($context, $copy);
        $context->builder->returnValue($copy);
        $context->builder->clearInsertionPosition();

        $fnEx = $context->lookupFunction('__string__ucwords_ex');
        $entryEx = $fnEx->appendBasicBlock('ucwords_ex_main');
        $context->builder->positionAtEnd($entryEx);

        $stringEx = $fnEx->getParam(0);
        $separatorsEx = $fnEx->getParam(1);
        $copyEx = $context->builder->call($context->lookupFunction('__string__separate'), $stringEx);
        self::transformInPlace($context, $copyEx, $separatorsEx);
        $context->builder->returnValue($copyEx);
        $context->builder->clearInsertionPosition();
    }

    public static function transformInPlace(Context $context, Value $strPtr, ?Value $separatorsPtr = null): void
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);

        $idxSlot = $context->builder->alloca($i64, 1, 'ucwords_idx');
        $wordStartSlot = $context->builder->alloca($i64, 1, 'ucwords_word_start');
        $foundSlot = null;
        if (null !== $separatorsPtr) {
            $foundSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1, 'ucwords_sep_found');
        }
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($one, $wordStartSlot);

        $done = BasicBlockHelper::append($context, 'ucwords_done');
        $loopHead = BasicBlockHelper::append($context, 'ucwords_head');
        $loopBody = BasicBlockHelper::append($context, 'ucwords_body');
        $loopBodyTail = null !== $separatorsPtr
            ? BasicBlockHelper::append($context, 'ucwords_body_tail')
            : null;
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $i32 = $context->getTypeFromString('int32');
        $atChar = $context->builder->gep($charPtr, $idx);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $wordStartFlag = $context->builder->load($wordStartSlot);
        $wordStart = $context->builder->icmp(Builder::INT_NE, $wordStartFlag, $zero);
        $lowerMin = $i32->constInt(ord('a'), false);
        $lowerMax = $i32->constInt(ord('z'), false);
        $inLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $lowerMin),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $lowerMax)
        );
        $shouldUpper = $context->builder->and($wordStart, $inLower);
        $upperCh = $context->builder->subNoSignedWrap($chI32, $i32->constInt(32, false));
        $newCh = $context->builder->truncOrBitCast(
            $context->builder->select($shouldUpper, $upperCh, $chI32),
            $ch->typeOf()
        );
        $context->builder->store($newCh, $atChar);
        if (null !== $separatorsPtr) {
            $sepEntry = self::emitCharInStringCheck($context, $chI32, $separatorsPtr, $foundSlot, $loopBodyTail);
            $context->builder->positionAtEnd($loopBody);
            $context->builder->branch($sepEntry);
        } else {
            $isSep = self::isWhitespaceByte($context, $chI32);
            $context->builder->store($context->builder->zExt($isSep, $i64), $wordStartSlot);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($loopHead);
        }

        if (null !== $loopBodyTail) {
            $context->builder->positionAtEnd($loopBodyTail);
            $isSep = $context->builder->load($foundSlot);
            $context->builder->store($context->builder->zExt($isSep, $i64), $wordStartSlot);
            $idx = $context->builder->load($idxSlot);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($loopHead);
        }

        $context->builder->positionAtEnd($done);
    }

    private static function isWhitespaceByte(Context $context, Value $ch): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $checks = [];
        foreach ([0x20, 0x09, 0x0A, 0x0D, 0x00, 0x0B] as $byte) {
            $checks[] = $context->builder->icmp(
                Builder::INT_EQ,
                $ch,
                $i32->constInt($byte, false)
            );
        }
        $result = $checks[0];
        for ($i = 1, $n = \count($checks); $i < $n; ++$i) {
            $result = $context->builder->or($result, $checks[$i]);
        }

        return $result;
    }

    /**
     * sep-check entry basic block (caller branches here from ucwords_body).
     *
     * @return mixed
     */
    private static function emitCharInStringCheck(
        Context $context,
        Value $ch,
        Value $strPtr,
        Value $foundSlot,
        $continueBlock
    ) {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $sepLen = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $sepChars = $context->builder->structGep($strPtr, $map['value']);

        $entry = BasicBlockHelper::append($context, 'ucwords_sep_entry');
        $idxSlot = $context->builder->alloca($i64, 1, 'ucwords_sep_idx');
        $context->builder->positionAtEnd($entry);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);
        $context->builder->store($zero, $idxSlot);

        $done = BasicBlockHelper::append($context, 'ucwords_sep_done');
        $loopHead = BasicBlockHelper::append($context, 'ucwords_sep_head');
        $loopBody = BasicBlockHelper::append($context, 'ucwords_sep_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $sepLen);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $atSepChar = $context->builder->gep($sepChars, $idx);
        $sepCh = $context->builder->load($atSepChar);
        $sepChI32 = $context->builder->zExt($sepCh, $i32);
        $matches = $context->builder->icmp(Builder::INT_EQ, $ch, $sepChI32);
        $found = $context->builder->load($foundSlot);
        $context->builder->store($context->builder->or($found, $matches), $foundSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->branch($continueBlock);

        return $entry;
    }
}
