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
use PHPCompiler\JIT\Builtin\StringExplode;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\VM\Variable;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPLLVM\Value;

/**
 * explode() with delimiter, string, and optional limit (php-src ext/standard/string.c).
 */
final class explode extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'explode() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'explode() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR: non-strict null → deprecate+coerce "" then ValueError empty separator
        // (php-src 8.1–8.x; #25942 reverts incorrect always-TypeError from #24695/#24717).
        // Caller strict_types still TypeError via stringBuiltinArgForFrame.
        $delimiter = VmString::stringBuiltinArgForFrame($frame, 0, 'explode', 0, 'separator');
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21189).
        $string = VmString::trimFamilyStringArgForFrame($frame, 1, 'explode', 1, 'string');
        $limit = \PHP_INT_MAX;
        if (3 === $argc) {
            $limit = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'explode', 3, 'limit');
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
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'explode() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'explode() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        // Null separator: JitStringBuiltinArg::lower emits DEP+coerce (or TypeError under
        // caller strict_types). Compile-time null/"" both hit empty-separator ValueError.
        $sepIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($sepIsNull || '' === ($args[0]->compileTimeString ?? null)) {
            if ($sepIsNull && $context->callerStrictTypes) {
                TypeErrorRaise::registerDeclarations($context);
                TypeErrorRaise::ensureLinked($context);
                $err = BasicBlockHelper::append($context, 'explode_null_sep_strict_err');
                $after = BasicBlockHelper::append($context, 'explode_null_sep_strict_after');
                $context->builder->branch($err);
                $context->builder->positionAtEnd($err);
                TypeErrorRaise::emitRaise(
                    $context,
                    'explode(): Argument #1 ($separator) must be of type string, null given'
                );
                $context->builder->call($context->lookupFunction('abort'));
                $context->builder->positionAtEnd($after);

                return HashTableHelper::alloc($context);
            }
            if ($sepIsNull && !$context->callerStrictTypes) {
                JitStringBuiltinArg::emitNullStringParamDeprecation(
                    $context,
                    'explode',
                    0,
                    'separator'
                );
            }
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            $err = BasicBlockHelper::append($context, 'explode_empty_sep_err');
            $after = BasicBlockHelper::append($context, 'explode_empty_sep_after');
            $context->builder->branch($err);
            $context->builder->positionAtEnd($err);
            TypeErrorRaise::emitValueError(
                $context,
                VmString::emptyStringArgValueErrorMessageCannot('explode', 0, 'separator')
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->positionAtEnd($after);

            return HashTableHelper::alloc($context);
        }

        $delimLit = $args[0]->compileTimeString ?? null;
        $hayLit = $args[1]->compileTimeString ?? null;
        if (3 === $argc) {
            $limitLit = self::compileTimeLimit($context, $args[2]);
            if (null !== $limitLit && null !== $delimLit && null !== $hayLit) {
                return JitExplode::buildPackedStrings($context, $delimLit, $hayLit, $limitLit);
            }
        } elseif (null !== $delimLit && null !== $hayLit) {
            return JitExplode::buildPackedStrings($context, $delimLit, $hayLit, \PHP_INT_MAX);
        }

        StringExplode::ensureLinked($context);
        $delimiter = JitStringBuiltinArg::lower($context, $args[0], 'explode', 0, 'separator');
        // Runtime empty separator (non-literal): ValueError then abort — peer substr_count (#30505).
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $delimiter,
            VmString::emptyStringArgValueErrorMessageCannot('explode', 0, 'separator')
        );
        $haystack = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'explode', 1, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'explode', 1, 'string');
        $i64 = $context->getTypeFromString('int64');
        if (3 === $argc) {
            $limitLit = self::compileTimeLimit($context, $args[2]);
            if (null !== $limitLit) {
                $limit = $context->constantFromInteger($limitLit, 'int64');
            } else {
                $limit = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'explode', 3, 'limit');
            }

            return StringExplode::invoke($context, $delimiter, $haystack, $limit);
        }

        return StringExplode::invoke(
            $context,
            $delimiter,
            $haystack,
            $i64->constInt(\PHP_INT_MAX, false)
        );
    }

    private static function compileTimeLimit(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && null !== ($arg->compileTimeDouble ?? null)) {
            if ($context->callerStrictTypes) {
                return null;
            }

            return VmMath::floatToZendLong((float) $arg->compileTimeDouble);
        }

        return null;
    }
}
