<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * trim() for strings (default whitespace mask; subset of PHP).
 */
final class string_trim extends Internal
{
    private static int $sliceBlockSerial = 0;

    private static int $trimBlockSerial = 0;

    public function __construct()
    {
        parent::__construct('trim');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('trim() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('trim() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::trim($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('trim() requires exactly one argument');
        }
        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal) {
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::trim($literal))
                )
            );
        }
        $str = $this->jitString($context, $args[0], 'string_trim() argument #1');
        $str = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $startSlot = $context->builder->alloca($i64, 1, 'trim_start');
        $endSlot = $context->builder->alloca($i64, 1, 'trim_end');
        $context->builder->store($zero, $startSlot);
        $context->builder->store($len, $endSlot);

        self::advanceWhileTrimByte($context, $charPtr, $len, $startSlot, true, 'trim');
        self::advanceWhileTrimByte($context, $charPtr, $len, $endSlot, false, 'trim');

        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $newLen = $context->builder->sub($end, $start);

        return self::jitCopySlice($context, $str, $charPtr, $start, $newLen, 'trim');
    }

    public static function jitCopySlice(
        Context $context,
        Value $str,
        Value $charPtr,
        Value $start,
        Value $sliceLen,
        string $blockId = ''
    ): Value {
        $suffix = ('' === $blockId ? '' : '_'.$blockId).'_'.(string) (++self::$sliceBlockSerial);
        $zero = JitStringIndex::zero($context);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $sliceLen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'slice_empty'.$suffix);
        $copyBlock = BasicBlockHelper::append($context, 'slice_copy'.$suffix);
        $doneBlock = BasicBlockHelper::append($context, 'slice_done'.$suffix);
        $context->builder->branchIf($isEmpty, $emptyBlock, $copyBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($copyBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $sliceLen);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $sliceLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $srcAt = $context->builder->gep($charPtr, $start);
        $destAt = $context->builder->structGep($dest, $destMap['value']);
        $context->intrinsic->memcpy($destAt, $srcAt, $sliceLen, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $copyBlock);

        BasicBlockHelper::branchToFreshContinue($context, 'slice_continue'.$suffix);

        return $result;
    }

    public static function advanceWhileTrimByte(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $indexSlot,
        bool $fromStart,
        string $blockId = ''
    ): void {
        $suffix = ('' === $blockId ? '' : '_'.$blockId).'_'.(string) (++self::$trimBlockSerial);
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);

        $done = BasicBlockHelper::append($context, ($fromStart ? 'trim_start_done' : 'trim_end_done').$suffix);
        $loopHead = BasicBlockHelper::append($context, ($fromStart ? 'trim_start_head' : 'trim_end_head').$suffix);
        $loopBody = BasicBlockHelper::append($context, ($fromStart ? 'trim_start_body' : 'trim_end_body').$suffix);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($indexSlot);
        if ($fromStart) {
            $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        } else {
            $stop = $context->builder->icmp(Builder::INT_SLE, $idx, $zero);
        }
        $context->builder->branchIf($stop, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $at = $fromStart
            ? $context->builder->gep($charPtr, $idx)
            : $context->builder->gep($charPtr, $context->builder->sub($idx, $one));
        $ch = $context->builder->load($at);
        $chI32 = $context->builder->zExt($ch, $context->getTypeFromString('int32'));
        $isTrim = self::jitIsTrimByte($context, $chI32);
        $continueLoop = $fromStart
            ? $context->builder->addNoSignedWrap($idx, $one)
            : $context->builder->sub($idx, $one);
        $context->builder->store(
            $context->builder->select($isTrim, $continueLoop, $idx),
            $indexSlot
        );
        $context->builder->branchIf($isTrim, $loopHead, $done);

        $context->builder->positionAtEnd($done);
    }

    private static function jitIsTrimByte(Context $context, Value $ch): Value
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
        for ($i = 1; $i < count($checks); ++$i) {
            $result = $context->builder->or($result, $checks[$i]);
        }

        return $result;
    }
}
