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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * bin2hex() for string arguments (subset of PHP).
 */
final class bin2hex extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('bin2hex() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('bin2hex() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::bin2hex($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('bin2hex() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('bin2hex() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $outLen = $context->builder->mulNoSignedWrap($len, $len->typeOf()->constInt(2, false));
        $out = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $outMap = $context->structFieldMap['__string__'];
        $outChars = $context->builder->structGep($out, $outMap['value']);
        $inChars = $context->builder->structGep($str, $map['value']);

        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $iSlot = $context->builder->alloca($i32, 1, 'bin2hex_i');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $context->builder->store($zero, $iSlot);

        $prev = $context->builder->getInsertBlock();
        $done = $prev->insertBasicBlock('bin2hex_done');
        $loopHead = $prev->insertBasicBlock('bin2hex_head');
        $loopBody = $prev->insertBasicBlock('bin2hex_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->gep($inChars, $i));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->logicalShiftRight($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->and($byteI32, $i32->constInt(0x0F, false));
        $hexBase = $i32->constInt(ord('0'), false);
        $hexOffset = $i32->constInt(10, false);
        $nine = $i32->constInt(9, false);
        $hiNibble = self::nibbleToHex($context, $hi, $hexBase, $hexOffset, $nine, $i32);
        $loNibble = self::nibbleToHex($context, $lo, $hexBase, $hexOffset, $nine, $i32);
        $outIdx = $context->builder->mulNoSignedWrap($i, $i32->constInt(2, false));
        $context->builder->store(
            $context->builder->truncOrBitCast($hiNibble, $i8),
            $context->builder->gep($outChars, $outIdx)
        );
        $context->builder->store(
            $context->builder->truncOrBitCast($loNibble, $i8),
            $context->builder->gep($outChars, $context->builder->addNoSignedWrap($outIdx, $one))
        );
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        return $out;
    }

    private static function nibbleToHex(
        Context $context,
        Value $nibble,
        Value $hexBase,
        Value $hexOffset,
        Value $nine,
        Value $i32
    ): Value {
        $isDigit = $context->builder->icmp(Builder::INT_ULE, $nibble, $nine);
        $letter = $context->builder->addNoSignedWrap(
            $context->builder->addNoSignedWrap($hexBase, $hexOffset),
            $nibble
        );
        $digit = $context->builder->addNoSignedWrap($hexBase, $nibble);

        return $context->builder->select($isDigit, $digit, $letter);
    }
}
