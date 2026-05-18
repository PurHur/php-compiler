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
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * explode() with delimiter and string (subset of PHP; VM only).
 */
final class explode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('explode() requires exactly two arguments in this compiler build');
        }
        $delimiter = $frame->calledArgs[0]->resolveIndirect()->toString();
        $string = $frame->calledArgs[1]->resolveIndirect()->toString();
        if (null === $frame->returnVar) {
            return;
        }
        $parts = VmString::explode($delimiter, $string);
        $ht = new HashTable();
        foreach ($parts as $part) {
            $value = new Variable();
            $value->string($part);
            $ht->append($value);
        }
        $frame->returnVar->array($ht);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('explode() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type
            || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('explode() only supports string arguments in this compiler build');
        }
        if ('' === ($args[0]->compileTimeString ?? null)) {
            throw new \LogicException('explode(): Argument #1 ($separator) cannot be empty');
        }
        $delimiter = $context->helper->loadValue($args[0]);
        $haystack = $context->helper->loadValue($args[1]);

        return JitExplode::explode($context, $delimiter, $haystack);
    }
}
