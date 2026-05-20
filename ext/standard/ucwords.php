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
 * ucwords() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class ucwords extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ucwords() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('ucwords() only supports strings in this compiler build');
        }
        $separators = VmString::UCWORDS_DEFAULT_SEPARATORS;
        if (2 === $argc) {
            $sep = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $sep->type) {
                throw new \LogicException('ucwords() second argument must be a string in this compiler build');
            }
            $separators = $sep->toString();
        }
        $frame->returnVar->string(VmString::asciiUcwords($v->toString(), $separators));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \LogicException('ucwords() JIT only supports one argument (default whitespace separators)');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('ucwords() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        self::transformUcwordsAscii($context, $copy);

        return $copy;
    }

    public static function transformUcwordsAscii(Context $context, Value $strPtr): void
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'ucwords_i');
        $capSlot = $context->builder->alloca($i8, 1, 'ucwords_cap');
        $context->builder->store($zero, $iSlot);
        $context->builder->store($i8->constInt(1, false), $capSlot);

        $done = BasicBlockHelper::append($context, 'ucwords_done');
        $loopHead = BasicBlockHelper::append($context, 'ucwords_head');
        $loopBody = BasicBlockHelper::append($context, 'ucwords_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $capNext = $context->builder->load($capSlot);
        $capNextI32 = $context->builder->zExt($capNext, $i32);
        $letterMin = $i32->constInt(ord('a'), false);
        $letterMax = $i32->constInt(ord('z'), false);
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $letterMin),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $letterMax)
        );
        $shouldUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $capNextI32, $i32->constInt(0, false)),
            $isLower
        );
        $offset = $i32->constInt(-32, false);
        $adjusted = $context->builder->addNoSignedWrap($chI32, $offset);
        $newChI32 = $context->builder->select($shouldUpper, $adjusted, $chI32);
        $newCh = $context->builder->truncOrBitCast($newChI32, $ch->typeOf());
        $context->builder->store($newCh, $atChar);

        $isSep = self::isDefaultSeparator($context, $chI32);
        $nextCap = $context->builder->select(
            $isSep,
            $i32->constInt(1, false),
            $i32->constInt(0, false)
        );
        $context->builder->store($context->builder->truncOrBitCast($nextCap, $i8), $capSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function isDefaultSeparator(Context $context, Value $chI32): Value
    {
        $builder = $context->builder;
        $eq = static fn (int $byte) => $builder->icmp(
            Builder::INT_EQ,
            $chI32,
            $chI32->typeOf()->constInt($byte, false)
        );

        return $builder->or(
            $eq(32),
            $builder->or(
                $eq(9),
                $builder->or(
                    $eq(10),
                    $builder->or(
                        $eq(13),
                        $builder->or($eq(12), $eq(11))
                    )
                )
            )
        );
    }
}
