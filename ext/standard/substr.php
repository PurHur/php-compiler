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
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'substr', 0, 'string');
        $offset = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'substr', 2, 'offset');
        if (3 === $argc) {
            $length = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL === $length->type) {
                $frame->returnVar->string(VmString::substr($string, $offsetInt));

                return;
            }
            $lengthInt = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'substr', 3, 'length');
            $frame->returnVar->string(VmString::substr($string, $offsetInt, $lengthInt));

            return;
        }
        $frame->returnVar->string(VmString::substr($string, $offsetInt));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('substr() requires two or three arguments');
        }
        $str = JitStringBuiltinArg::lower($context, $args[0], 'substr', 0, 'string');
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);
        $zero = JitStringIndex::zero($context);
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'substr', 2, 'offset');
        $start = JitStringIndex::clamp($context, $offset, $zero, $len);

        $sliceLen = null;
        if (3 === $argc) {
            if (JITVariable::TYPE_VALUE === $args[2]->type && $args[2]->isNullConstant) {
                $sliceLen = $context->builder->sub($len, $start);
                $sliceLen = JitStringIndex::max($context, $sliceLen, $zero);
            } else {
                $lengthArg = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'substr', 3, 'length');
                $negLen = $context->builder->icmp(Builder::INT_SLT, $lengthArg, $zero);
                $remaining = $context->builder->sub($len, $start);
                $maxLen = $context->builder->select($negLen, $zero, $lengthArg);
                $sliceLen = JitStringIndex::min($context, $maxLen, $remaining);
            }
        } else {
            $sliceLen = $context->builder->sub($len, $start);
            $sliceLen = JitStringIndex::max($context, $sliceLen, $zero);
        }

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen);
    }

}
