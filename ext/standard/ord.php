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
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Ordinal value of the first byte of a string (ext/standard/string.c parity, #4331).
 */
final class ord extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('ord() requires exactly one argument');
        }
        $s = InternalStrictArg::requireString($frame, 0, 'ord', 'character')->toString();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int('' === $s ? 0 : \ord($s));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('ord() requires exactly one argument');
        }

        JitInternalStrictArg::requireString($context, $args[0], 'ord', 'character', 1);
        $strPtr = $this->jitString($context, $args[0], 'ord() argument #1');
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $lenPtr = $context->builder->structGep($strPtr, $map['length']);
        $len = $context->builder->load($lenPtr);
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $ch = $context->builder->load($charPtr);
        $zeroByte = $ch->typeOf()->constInt(0, false);
        $byte = $context->builder->select($isEmpty, $zeroByte, $ch);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($byte, $i64);
    }
}
