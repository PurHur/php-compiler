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
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $delimiter = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'explode', 0, 'separator');
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'explode', 1, 'string');
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
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            $err = BasicBlockHelper::append($context, 'explode_empty_sep_err');
            $after = BasicBlockHelper::append($context, 'explode_empty_sep_after');
            $context->builder->branch($err);
            $context->builder->positionAtEnd($err);
            TypeErrorRaise::emitValueError($context, 'explode(): Argument #1 ($separator) cannot be empty');
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->positionAtEnd($after);

            return HashTableHelper::alloc($context);
        }
        $delimiter = JitStringBuiltinArg::lower($context, $args[0], 'explode', 0, 'separator');
        $haystack = JitStringBuiltinArg::lower($context, $args[1], 'explode', 1, 'string');
        if (3 === $argc) {
            $limitLit = self::compileTimeLimit($args[2]);
            $delimLit = $args[0]->compileTimeString ?? null;
            $hayLit = $args[1]->compileTimeString ?? null;
            if (null !== $limitLit && null !== $delimLit && null !== $hayLit) {
                return JitExplode::buildPackedStrings($context, $delimLit, $hayLit, $limitLit);
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
