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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * explode() with delimiter, string, and optional limit (php-src ext/standard/string.c).
 */
final class explode extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('explode() expects 2 or 3 arguments in this compiler build');
        }
        $delimiter = $frame->calledArgs[0]->resolveIndirect()->toString();
        $string = $frame->calledArgs[1]->resolveIndirect()->toString();
        $limit = \PHP_INT_MAX;
        if (3 === $argc) {
            $limitArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitArg->type) {
                throw new \TypeError('explode(): Argument #3 ($limit) must be of type int');
            }
            $limit = $limitArg->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parts = VmString::explode($delimiter, $string, $limit);
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('explode() expects 2 or 3 arguments in this compiler build');
        }
        if ('' === ($args[0]->compileTimeString ?? null)) {
            throw new \LogicException('explode(): Argument #1 ($separator) cannot be empty');
        }
        $delimiter = $this->jitString($context, $args[0], 'explode() argument #1');
        $haystack = $this->jitString($context, $args[1], 'explode() argument #2');
        if (3 === $argc) {
            $limitLit = self::compileTimeLimit($args[2]);
            $delimLit = $args[0]->compileTimeString ?? null;
            $hayLit = $args[1]->compileTimeString ?? null;
            if (null !== $limitLit && null !== $delimLit && null !== $hayLit) {
                return JitExplode::buildPackedStrings($context, $delimLit, $hayLit, $limitLit);
            }
            if (null !== $limitLit && $limitLit < 0) {
                throw new \LogicException(
                    'explode() negative limit requires compile-time arguments in JIT/AOT in this compiler build'
                );
            }
            $limit = null !== $limitLit
                ? $context->constantFromInteger($limitLit, 'int64')
                : JitLongArg::lower($context, $args[2], 'explode() argument #3 ($limit)');

            return JitExplode::explode($context, $delimiter, $haystack, $limit);
        }

        return JitExplode::explode($context, $delimiter, $haystack);
    }

    private static function compileTimeLimit(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
            || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }
}
