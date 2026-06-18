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
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * iterator_to_array() — copy Traversable (array or Generator) into an array (#3100).
 *
 * VM: {@see VM::iteratorToArray()}; JIT: {@see JitIteratorToArray}.
 * Default preserve_keys=true matches Zend/php-src (ext/spl/iterator.c).
 *
 * php-src: ext/spl/iterator.c — PHP_FUNCTION(iterator_to_array)
 */
final class iterator_to_array extends Internal
{
    public function __construct()
    {
        parent::__construct('iterator_to_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('iterator_to_array() requires one or two arguments in this compiler build');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('iterator_to_array() requires VM context in this compiler build');
        }
        $iterator = $frame->calledArgs[0]->resolveIndirect();
        $preserveKeys = true;
        if (2 === $argc) {
            $preserveKeys = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $out = $frame->vmContext->runtime->vm->iteratorToArray($iterator, $preserveKeys, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('iterator_to_array() requires one or two arguments in this compiler build');
        }
        if (2 === $argc) {
            $preserveKeys = JitBoolArg::lower($context, $args[1], 'iterator_to_array() preserve_keys');

            return JitIteratorToArray::invokeWithPreserveKeysFlag($context, $args[0], $preserveKeys);
        }

        return JitIteratorToArray::invoke($context, $args[0], true);
    }
}
