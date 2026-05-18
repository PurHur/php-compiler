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
 * strrev() for strings (subset of PHP; byte reversal).
 */
final class strrev extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strrev() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('strrev() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::strrev($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('strrev() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('strrev() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'strrev_empty');
        $workBlock = BasicBlockHelper::append($context, 'strrev_work');
        $doneBlock = BasicBlockHelper::append($context, 'strrev_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $len,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $idxSlot = $context->builder->alloca($i64, 1, 'strrev_idx');
        $context->builder->store($zero, $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'strrev_head');
        $loopBody = BasicBlockHelper::append($context, 'strrev_body');
        $loopDone = BasicBlockHelper::append($context, 'strrev_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $srcAt = $context->builder->gep($charPtr, $idx);
        $srcCh = $context->builder->load($srcAt);
        $mirrorIdx = $context->builder->sub(
            $context->builder->sub($len, $one),
            $idx
        );
        $destAt = $context->builder->gep($destPtr, $mirrorIdx);
        $context->builder->store($srcCh, $destAt);
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
