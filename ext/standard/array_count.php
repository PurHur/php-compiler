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
    public function __construct(string $name = 'count')
    {
        parent::__construct($name);
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
        if (1 !== count($args)) {
            throw new \LogicException('count() requires exactly one argument');
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromInteger($args[0]->nextFreeElement, 'int64');
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            throw new \LogicException('count() on HashTable arrays is not implemented for JIT in this compiler build');
        }
        throw new \LogicException('count() only supports native arrays in this compiler build');
    }
}
