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
 * lcfirst() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class lcfirst extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('lcfirst() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('lcfirst() only supports strings in this compiler build');
        }
        $frame->returnVar->string(\lcfirst($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('lcfirst() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('lcfirst() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        self::transformFirstAscii($context, $copy, ord('A'), ord('Z'), 32);

        return $copy;
    }

    public static function transformFirstAscii(Context $context, Value $strPtr, int $letterMin, int $letterMax, int $delta): void
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $prev = $context->builder->getInsertBlock();
        $done = $prev->insertBasicBlock('case_transform_done');
        $work = $prev->insertBasicBlock('case_transform_work');
        $context->builder->branchIf($isEmpty, $done, $work);
        $context->builder->positionAtEnd($work);
        $valGep = $context->builder->structGep($strPtr, $map['value']);
        $ch = $context->builder->load($valGep);
        $i32 = $context->builder->zExt($ch, $context->getTypeFromString('int32'));
        $min = $i32->typeOf()->constInt($letterMin, false);
        $max = $i32->typeOf()->constInt($letterMax, false);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $i32, $min),
            $context->builder->icmp(Builder::INT_SLE, $i32, $max)
        );
        $offset = $i32->typeOf()->constInt($delta, false);
        $adjusted = $context->builder->addNoSignedWrap($i32, $offset);
        $newCh = $context->builder->truncOrBitCast(
            $context->builder->select($inRange, $adjusted, $i32),
            $ch->typeOf()
        );
        $context->builder->store($newCh, $valGep);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }
}
