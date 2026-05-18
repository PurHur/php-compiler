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
 * count() for arrays (subset of PHP; VM only in this compiler build).
 */
final class array_count extends Internal
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('count() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $v->type) {
            throw new \LogicException('count() only supports arrays in this compiler build');
        }
        $frame->returnVar->int($v->toArray()->getNumElements());
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('count() is not implemented for JIT in this compiler build');
    }
}
