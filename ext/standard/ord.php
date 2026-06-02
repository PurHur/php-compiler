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
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Ordinal value of the first byte of a non-empty string (ext/standard/string.c parity, #4324).
 */
final class ord extends Internal
{
    private const EMPTY_ERROR = 'ord(): Argument #1 ($string) must not be empty';

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('ord() requires exactly one argument');
        }
        $s = $frame->calledArgs[0]->resolveIndirect()->toString();
        if ('' === $s) {
            throw new \ValueError(self::EMPTY_ERROR);
        }
        if (null === $frame->returnVar) {
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
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        $strPtr = $this->jitString($context, $args[0], 'ord() argument #1');
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $lenPtr = $context->builder->structGep($strPtr, $map['length']);
        $len = $context->builder->load($lenPtr);
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $okBlock = BasicBlockHelper::append($context, 'ord_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'ord_len_err');
        $context->builder->branchIf($isEmpty, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::emitValueError($context, self::EMPTY_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);

        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $ch = $context->builder->load($charPtr);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($ch, $i64);
    }
}
