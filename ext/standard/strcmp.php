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

final class strcmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('strcmp() requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect()->toString();
        $b = $frame->calledArgs[1]->resolveIndirect()->toString();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strcmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('strcmp() requires exactly two arguments');
        }
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'strcmp() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'strcmp() argument #2'));
        $fn = $context->lookupFunction('strcmp');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }

}
