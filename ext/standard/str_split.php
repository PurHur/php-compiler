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
 * str_split() for strings (subset of PHP; native LLVM in JIT).
 */
final class str_split extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('str_split() requires one or two arguments');
        }
        $string = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $string->type) {
            throw new \LogicException('str_split() argument must be a string in this compiler build');
        }
        $length = 1;
        if (2 === $argc) {
            $lenArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenArg->type) {
                throw new \LogicException('str_split() length must be an integer in this compiler build');
            }
            $length = $lenArg->toInt();
        }
        $parts = VmString::strSplit($string->toString(), $length);
        $out = new HashTable();
        foreach ($parts as $part) {
            $stored = new Variable();
            $stored->string($part);
            $out->append($stored);
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('str_split() requires one or two arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('str_split() argument must be a string in this compiler build');
        }
        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal) {
            $chunkLenInt = 1;
            if (2 === $argc) {
                $chunkLenInt = JitStrSplit::compileTimeLong($context, $args[1]);
            }

            return JitStrSplit::buildPackedStrings($context, $literal, $chunkLenInt);
        }
        $chunkLen = $context->constantFromInteger(1, 'int64');
        if (2 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('str_split() length must be an integer in this compiler build');
            }
            $chunkLen = $context->helper->loadValue($args[1]);
        }

        return JitStrSplit::split(
            $context,
            $context->helper->loadValue($args[0]),
            $chunkLen
        );
    }
}
