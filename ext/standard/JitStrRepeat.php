<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for str_repeat() — native string repeat without PHP builtins.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrRepeat
{
    private const TIMES_ERROR = 'str_repeat(): Argument #2 ($times) must be greater than or equal to 0';

    /**
     * Runtime guard for negative multiplier (issue #3735; php-src ext/standard/string.c).
     */
    public static function emitRuntimeTimesGuard(Context $context, Value $multiplier): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $multiplier, $zero);
        $okBlock = BasicBlockHelper::append($context, 'strrepeat_times_ok');
        $errBlock = BasicBlockHelper::append($context, 'strrepeat_times_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::TIMES_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    public static function repeat(Context $context, Value $input, Value $multiplier): Value
    {
        $map = $context->structFieldMap['__string__'];
        $inputLen = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $inputPtr = $context->builder->structGep($input, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $totalLen = $context->builder->mul($inputLen, $multiplier);

        $emptyBlock = BasicBlockHelper::append($context, 'strrepeat_empty');
        $workBlock = BasicBlockHelper::append($context, 'strrepeat_work');
        $doneBlock = BasicBlockHelper::append($context, 'strrepeat_done');
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $totalLen, $zero);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $totalLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $totalLen,
            $context->builder->structGep($dest, $map['length'])
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'strrepeat_idx');
        $context->builder->store($zero, $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'strrepeat_head');
        $loopBody = BasicBlockHelper::append($context, 'strrepeat_body');
        $loopDone = BasicBlockHelper::append($context, 'strrepeat_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $multiplier);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $at = $context->builder->mul($idx, $inputLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $at),
            $inputPtr,
            $inputLen,
            false
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $loopDone);

        return $result;
    }
}
