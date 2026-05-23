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
 * substr() for strings with integer offset and optional length (subset of PHP).
 */
final class substr extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('substr() requires two or three arguments');
        }
        $s = $frame->calledArgs[0]->resolveIndirect();
        $offset = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $s->type || Variable::TYPE_INTEGER !== $offset->type) {
            throw new \LogicException('substr() requires a string and integer offset in this compiler build');
        }
        if (3 === $argc) {
            $length = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $length->type) {
                throw new \LogicException('substr() length must be an integer in this compiler build');
            }
            $frame->returnVar->string(VmString::substr($s->toString(), $offset->toInt(), $length->toInt()));

            return;
        }
        $frame->returnVar->string(VmString::substr($s->toString(), $offset->toInt()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('substr() requires two or three arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('substr() requires a string and integer offset in this compiler build');
        }
        if (3 === $argc && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr() length must be an integer in this compiler build');
        }

        $str = $this->jitString($context, $args[0], 'substr() argument #1');
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);
        $zero = JitStringIndex::zero($context);
        $offset = $context->helper->loadValue($args[1]);
        $start = JitStringIndex::clamp($context, $offset, $zero, $len);

        if (3 === $argc) {
            $lengthArg = $context->helper->loadValue($args[2]);
            $negLen = $context->builder->icmp(Builder::INT_SLT, $lengthArg, $zero);
            $remaining = $context->builder->sub($len, $start);
            $maxLen = $context->builder->select($negLen, $zero, $lengthArg);
            $sliceLen = JitStringIndex::min($context, $maxLen, $remaining);
        } else {
            $sliceLen = $context->builder->sub($len, $start);
            $sliceLen = JitStringIndex::max($context, $sliceLen, $zero);
        }

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen);
    }

}
