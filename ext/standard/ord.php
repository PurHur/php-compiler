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
 * Ordinal value of the first byte of a non-empty string, or 0 for empty string
 * (subset behaviour; PHP 8 throws ValueError on empty string).
 */
final class ord extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('ord() requires exactly one argument');
        }
        $s = $frame->calledArgs[0]->resolveIndirect()->toString();
        if (null === $frame->returnVar) {
            return;
        }
        if ('' === $s) {
            $frame->returnVar->int(0);

            return;
        }
        $frame->returnVar->int(\ord($s));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('ord() requires exactly one argument');
        }
        $strPtr = $this->jitString($context, $args[0], 'ord() argument #1');
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $lenPtr = $context->builder->structGep($strPtr, $map['length']);
        $len = $context->builder->load($lenPtr);
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $ch = $context->builder->load($charPtr);
        $i64 = $context->getTypeFromString('int64');
        $extended = $context->builder->zExt($ch, $i64);

        return $context->builder->select($isEmpty, $zero, $extended);
    }
}
