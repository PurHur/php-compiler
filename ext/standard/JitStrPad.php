<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for str_pad() — STR_PAD_LEFT, STR_PAD_RIGHT, STR_PAD_BOTH.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrPad
{
    private const PAD_STRING_ERROR = 'str_pad(): Argument #3 ($pad_string) must be a non-empty string';

    /**
     * Runtime guard for empty pad string (issue #3762; avoids div-by-zero in fillPadding()).
     */
    public static function emitRuntimeEmptyPadStringGuard(Context $context, Value $padString): void
    {
        $map = $context->structFieldMap['__string__'];
        $padLen = $context->builder->load(
            $context->builder->structGep($padString, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $invalid = $context->builder->icmp(Builder::INT_EQ, $padLen, $zero);
        $okBlock = BasicBlockHelper::append($context, 'strpad_padstr_ok');
        $errBlock = BasicBlockHelper::append($context, 'strpad_padstr_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::PAD_STRING_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    public static function pad(
        Context $context,
        Value $input,
        Value $padLength,
        Value $padString,
        Value $padType
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $inputLen = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $inputPtr = $context->builder->structGep($input, $map['value']);
        $padLen = $context->builder->load(
            $context->builder->structGep($padString, $map['length'])
        );
        $padPtr = $context->builder->structGep($padString, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $padLeftConst = $i64->constInt(0, false);

        $noPad = $context->builder->icmp(Builder::INT_SLE, $padLength, $inputLen);
        $shortBlock = BasicBlockHelper::append($context, 'strpad_short');
        $workBlock = BasicBlockHelper::append($context, 'strpad_work');
        $doneBlock = BasicBlockHelper::append($context, 'strpad_done');
        $context->builder->branchIf($noPad, $shortBlock, $workBlock);

        $context->builder->positionAtEnd($shortBlock);
        $unchanged = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $need = $context->builder->sub($padLength, $inputLen);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $padLength);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $padLength,
            $context->builder->structGep($dest, $map['length'])
        );

        $padBothConst = $i64->constInt(2, false);
        $isLeft = $context->builder->icmp(Builder::INT_EQ, $padType, $padLeftConst);
        $isBoth = $context->builder->icmp(Builder::INT_EQ, $padType, $padBothConst);
        $leftBlock = BasicBlockHelper::append($context, 'strpad_left');
        $bothBlock = BasicBlockHelper::append($context, 'strpad_both');
        $rightBlock = BasicBlockHelper::append($context, 'strpad_right');
        $typeCheckBlock = BasicBlockHelper::append($context, 'strpad_type_check');
        $joinedBlock = BasicBlockHelper::append($context, 'strpad_joined');
        $context->builder->branchIf($isLeft, $leftBlock, $typeCheckBlock);

        $context->builder->positionAtEnd($typeCheckBlock);
        $context->builder->branchIf($isBoth, $bothBlock, $rightBlock);

        $context->builder->positionAtEnd($leftBlock);
        self::fillPadding($context, $destPtr, $zero, $need, $padPtr, $padLen, '_left');
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $need),
            $inputPtr,
            $inputLen,
            false
        );
        $context->builder->branch($joinedBlock);

        $context->builder->positionAtEnd($bothBlock);
        $leftNeed = $context->builder->signedDiv($need, $i64->constInt(2, false));
        $rightNeed = $context->builder->sub($need, $leftNeed);
        self::fillPadding($context, $destPtr, $zero, $leftNeed, $padPtr, $padLen, '_both_l');
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $leftNeed),
            $inputPtr,
            $inputLen,
            false
        );
        self::fillPadding(
            $context,
            $context->builder->gep($destPtr, $context->builder->add($leftNeed, $inputLen)),
            $zero,
            $rightNeed,
            $padPtr,
            $padLen,
            '_both_r'
        );
        $context->builder->branch($joinedBlock);

        $context->builder->positionAtEnd($rightBlock);
        $context->intrinsic->memcpy($destPtr, $inputPtr, $inputLen, false);
        self::fillPadding(
            $context,
            $context->builder->gep($destPtr, $inputLen),
            $zero,
            $need,
            $padPtr,
            $padLen,
            '_right'
        );
        $context->builder->branch($joinedBlock);

        $context->builder->positionAtEnd($joinedBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($unchanged, $shortBlock);
        $result->addIncoming($dest, $joinedBlock);

        return $result;
    }

    private static function fillPadding(
        Context $context,
        Value $destAt,
        Value $zero,
        Value $need,
        Value $padPtr,
        Value $padLen,
        string $suffix
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);

        $idxSlot = $context->builder->alloca($i64, 1, 'strpad_pad_idx'.$suffix);
        $context->builder->store($zero, $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'strpad_pad_head'.$suffix);
        $loopBody = BasicBlockHelper::append($context, 'strpad_pad_body'.$suffix);
        $loopDone = BasicBlockHelper::append($context, 'strpad_pad_done'.$suffix);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $need);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $padIdx = $context->builder->unsigendRem($idx, $padLen);
        $ch = $context->builder->load($context->builder->gep($padPtr, $padIdx));
        $context->builder->store($ch, $context->builder->gep($destAt, $idx));
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
    }
}
