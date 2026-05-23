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
use PHPLLVM\Value;

/**
 * strcasecmp() for two strings (ASCII case fold subset; LLVM via libc).
 */
final class strcasecmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('strcasecmp() requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect()->toString();
        $b = $frame->calledArgs[1]->resolveIndirect()->toString();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strcasecmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('strcasecmp() requires exactly two arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('strcasecmp() only supports strings in this compiler build');
        }
        $p0 = $this->stringDataPtr($context, $context->helper->loadValue($args[0]));
        $p1 = $this->stringDataPtr($context, $context->helper->loadValue($args[1]));
        $fn = $context->lookupFunction('strcasecmp');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
