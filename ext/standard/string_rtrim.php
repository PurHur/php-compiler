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
use PHPLLVM\Value;

/**
 * rtrim() for strings (default whitespace mask; subset of PHP).
 */
final class string_rtrim extends Internal
{
    public function __construct()
    {
        parent::__construct('rtrim');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('rtrim() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('rtrim() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::rtrim($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('rtrim() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('rtrim() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $endSlot = $context->builder->alloca($i32, 1, 'rtrim_end');
        $context->builder->store($len, $endSlot);
        string_trim::advanceWhileTrimByte($context, $charPtr, $len, $endSlot, false);

        $end = $context->builder->load($endSlot);

        return string_trim::jitCopySlice($context, $str, $charPtr, $zero, $end);
    }
}
