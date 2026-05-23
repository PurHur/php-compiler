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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Single-byte string from an integer code point (subset: same modular reduction as PHP 7).
 */
final class chr extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('chr() requires exactly one argument');
        }
        $n = $frame->calledArgs[0]->resolveIndirect()->toInt();
        if (null === $frame->returnVar) {
            return;
        }
        $byte = (($n % 256) + 256) % 256;
        $frame->returnVar->string(\chr($byte));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('chr() requires exactly one argument');
        }
        $v = JitLongArg::lower($context, $args[0], 'chr() codepoint');
        $const256 = $v->typeOf()->constInt(256, false);
        $rem = $context->builder->signedRem($v, $const256);
        $zero = $v->typeOf()->constInt(0, false);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $rem, $zero);
        $adjusted = $context->builder->select(
            $isNeg,
            $context->builder->addNoSignedWrap($rem, $const256),
            $rem
        );
        $i8 = $context->context->int8Type();
        $byte = $context->builder->truncOrBitCast($adjusted, $i8);
        $one = $v->typeOf()->constInt(1, false);
        $allocFn = $context->lookupFunction('__string__alloc');
        $str = $context->builder->call($allocFn, $one);
        $map = $context->structFieldMap['__string__'];
        $valGep = $context->builder->structGep($str, $map['value']);
        $context->builder->store($byte, $valGep);

        return $str;
    }
}
