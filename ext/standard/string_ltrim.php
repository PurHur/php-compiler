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
 * ltrim() for strings (default whitespace mask; subset of PHP).
 */
final class string_ltrim extends Internal
{
    public function __construct()
    {
        parent::__construct('ltrim');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('ltrim() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('ltrim() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::ltrim($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('ltrim() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('ltrim() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $startSlot = $context->builder->alloca($i64, 1, 'ltrim_start');
        $context->builder->store($zero, $startSlot);
        string_trim::advanceWhileTrimByte($context, $charPtr, $len, $startSlot, true);

        $start = $context->builder->load($startSlot);
        $sliceLen = $context->builder->sub($len, $start);

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen);
    }
}
