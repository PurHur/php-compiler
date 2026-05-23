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
 * hexdec() for string arguments (subset of PHP standard library).
 */
final class hexdec extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('hexdec() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('hexdec() only supports strings in this compiler build');
        }
        $frame->returnVar->int((int) \hexdec($v->toString()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('hexdec() requires exactly one argument');
        }
        $ptr = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'hexdec() argument #1'));
        $endPtr = $context->getTypeFromString('int8**')->constPointerNull();
        $base = $context->getTypeFromString('int32')->constInt(16, false);
        $fn = $context->lookupFunction('strtol');
        $raw = $context->builder->call($fn, $ptr, $endPtr, $base);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->trunc($raw, $i64);
    }
}
