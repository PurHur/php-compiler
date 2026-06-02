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
use PHPCompiler\JIT\Builtin\StringTrimMask;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * trim() for strings (default whitespace or optional $characters mask; php-src string.c).
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
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('trim() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('trim() only supports strings in this compiler build');
        }
        $mask = VmString::TRIM_DEFAULT;
        if (2 === $argc) {
            $maskArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $maskArg->type) {
                throw new \LogicException('trim() character mask must be a string in this compiler build');
            }
            $mask = $maskArg->toString();
        }
        $frame->returnVar->string(VmString::trim($v->toString(), $mask));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('trim() requires one or two arguments');
        }
        $literal = $args[0]->compileTimeString ?? null;
        $maskLiteral = (2 === $argc) ? ($args[1]->compileTimeString ?? null) : null;
        if (null !== $literal && (1 === $argc || null !== $maskLiteral)) {
            $mask = null !== $maskLiteral ? $maskLiteral : VmString::TRIM_DEFAULT;

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::trim($literal, $mask))
                )
            );
        }
        if (2 === $argc) {
            StringTrimMask::ensureLinked($context);
        }
        $str = $this->jitString($context, $args[0], 'string_trim() argument #1');
        $str = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $maskStr = (2 === $argc)
            ? $this->jitString($context, $args[1], 'string_trim() argument #2')
            : null;
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $startSlot = $context->builder->alloca($i64, 1, 'trim_start');
        $endSlot = $context->builder->alloca($i64, 1, 'trim_end');
        $context->builder->store($zero, $startSlot);
        $context->builder->store($len, $endSlot);

        self::advanceWhileTrimByte($context, $charPtr, $len, $startSlot, true, 'trim', $maskStr);
        self::advanceWhileTrimByte($context, $charPtr, $len, $endSlot, false, 'trim', $maskStr);

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
        string $blockId = '',
        ?Value $maskStr = null
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
        $isTrim = self::jitIsMaskByte($context, $chI32, $maskStr);
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

    private static function jitIsMaskByte(Context $context, Value $ch, ?Value $maskStr): Value
    {
        if (null === $maskStr) {
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

        $maskStr = $context->builder->call($context->lookupFunction('__string__separate'), $maskStr);
        $i32 = $context->getTypeFromString('int32');
        $inMask = $context->builder->call(
            $context->lookupFunction('__phpc_char_in_mask'),
            $ch,
            $maskStr
        );

        return $context->builder->icmp(Builder::INT_NE, $inMask, $i32->constInt(0, false));
    }
}
