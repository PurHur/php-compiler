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
 * iterator_to_array() — copy Traversable (array or Generator) into an array (#3100).
 *
 * VM: {@see VM::iteratorToArray()}; JIT: {@see JitIteratorToArray}.
 * Generator preserve_keys uses yielded keys when set (#3085); string yield keys still auto-indexed on VM.
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
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('iterator_to_array() requires VM context in this compiler build');
        }
        $iterator = $frame->calledArgs[0]->resolveIndirect();
        $preserveKeys = false;
        if (2 === $argc) {
            $flag = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException('iterator_to_array() second argument must be bool in this compiler build');
            }
            $preserveKeys = $flag->toBool();
        }
        $out = $frame->vmContext->runtime->vm->iteratorToArray($iterator, $preserveKeys);
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('iterator_to_array() requires one or two arguments in this compiler build');
        }
        $preserveKeys = false;
        if (2 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[1]->type || !($args[1]->isConstant ?? false)) {
                throw new \LogicException(
                    'iterator_to_array() second argument must be a compile-time bool in this compiler build'
                );
            }
            $preserveKeys = (bool) $args[1]->value;
        }

        return JitIteratorToArray::invoke($context, $args[0], $preserveKeys);
    }
}
