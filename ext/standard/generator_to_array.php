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
 * generator_to_array() — materialize a Generator into an array (PHP 8.4, issue #6025).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(generator_to_array)
 */
final class generator_to_array extends Internal
{
    public function __construct()
    {
        parent::__construct('generator_to_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('generator_to_array() requires one or two arguments in this compiler build');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('generator_to_array() requires VM context in this compiler build');
        }
        $generator = VmGeneratorArray::assertGenerator($frame->calledArgs[0], 'generator_to_array');
        $preserveKeys = false;
        if (2 === $argc) {
            $preserveKeys = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $out = $frame->vmContext->runtime->vm->iteratorToArray($generator, $preserveKeys);
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('generator_to_array() requires one or two arguments in this compiler build');
        }
        if (2 === $argc) {
            $preserveKeys = JitBoolArg::lower($context, $args[1], 'generator_to_array() preserve_keys');

            return JitGeneratorToArray::invokeWithPreserveKeysFlag($context, $args[0], $preserveKeys);
        }

        return JitGeneratorToArray::invoke($context, $args[0], false);
    }
}
