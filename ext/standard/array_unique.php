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
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_unique() for arrays of scalar values (ext/standard/array.c php_array_unique subset).
 */
final class array_unique extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_unique() requires one or two arguments');
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_unique', 1, 'array');
        $flags = self::resolveVmFlags($frame, $argc);
        $out = new HashTable();
        $seen = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            self::assertUniqueElement($frame, $value, $flags);
            if (self::isDuplicate($value, $seen, $flags)) {
                continue;
            }
            $seenCopy = new Variable();
            $seenCopy->copyFrom($value);
            $seen[] = $seenCopy;
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $stored);
            } else {
                $out->add($key->toString(), $stored);
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($out);
    }

    private static function resolveVmFlags(Frame $frame, int $argc): int
    {
        if (1 === $argc) {
            return StdlibConstants::SORT_STRING;
        }
        $flagsArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $flagsArg->type) {
            throw new \LogicException('array_unique() flags must be an integer in this compiler build');
        }

        return self::normalizeFlags($flagsArg->toInt());
    }

    /**
     * SORT_STRING: objects and enum cases without __toString must throw (ext/standard/array.c, #4698, #5531).
     * SORT_REGULAR compares objects by zend compare rules — no string cast (#9318).
     */
    private static function assertUniqueElement(Frame $frame, Variable $value, int $flags): void
    {
        if (StdlibConstants::SORT_STRING !== ($flags & ~StdlibConstants::SORT_FLAG_CASE)) {
            return;
        }
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            throw new \Error(
                'Object of class '.EnumCaseSupport::typeNameForVariable($value).' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            return;
        }
        if (null === $frame->vmContext || null === $frame->vmContext->runtime->vm) {
            throw new \Error(
                'Object of class '.$value->toObject()->class->name.' could not be converted to string'
            );
        }
        $frame->vmContext->runtime->vm->castObjectToString($value->toObject());
    }

    private static function normalizeFlags(int $flags): int
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (
            StdlibConstants::SORT_REGULAR !== $sortType
            && StdlibConstants::SORT_STRING !== $sortType
            && StdlibConstants::SORT_NUMERIC !== $sortType
        ) {
            throw new \LogicException(
                'array_unique() flags are not supported in this compiler build'
            );
        }

        return $flags;
    }

    /**
     * @param list<Variable> $seen
     */
    private static function isDuplicate(Variable $value, array $seen, int $flags): bool
    {
        foreach ($seen as $seenValue) {
            if (self::valuesMatchForUnique($value, $seenValue, $flags)) {
                return true;
            }
        }

        return false;
    }

    private static function valuesMatchForUnique(Variable $a, Variable $b, int $flags): bool
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_STRING === $sortType) {
            return $a->resolveIndirect()->toString() === $b->resolveIndirect()->toString();
        }
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return 0 === self::compareNumericOperandsForUnique($a, $b);
        }

        return $a->equals($b);
    }

    /** numeric_compare_function equality for array_unique SORT_NUMERIC (ext/standard/array.c). */
    private static function compareNumericOperandsForUnique(Variable $a, Variable $b): int
    {
        $av = self::numericUniqueScalar($a);
        $bv = self::numericUniqueScalar($b);
        if (\is_float($av) || \is_float($bv)) {
            return (float) $av <=> (float) $bv;
        }

        return (int) $av <=> (int) $bv;
    }

    private static function numericUniqueScalar(Variable $value): int|float
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_INTEGER === $value->type) {
            return $value->toInt();
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            return $value->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $value->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $value->type) {
            $s = $value->toString();
            if (is_numeric($s)) {
                if (((string) (int) $s) === $s
                    && !str_contains($s, '.')
                    && !str_contains(strtolower($s), 'e')) {
                    return (int) $s;
                }

                return (float) $s;
            }
            if (!preg_match('/^\s*[+-]?(?:(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $s, $m)) {
                return 0;
            }
            $numPart = ltrim($m[0], " \t\n\r\0\x0B");
            if (((string) (int) $numPart) === $numPart
                && !str_contains($numPart, '.')
                && !str_contains(strtolower($numPart), 'e')) {
                return (int) $numPart;
            }

            return (float) $numPart;
        }

        return 0;
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_unique() requires one or two arguments');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_unique() argument #'.((int) $i + 1));
            }
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_unique', 1, 'array');
        $flags = StdlibConstants::SORT_STRING;
        if (2 === $argc) {
            $flags = self::resolveJitFlags($context, $args[1]);
        }

        return ArrayBuiltinHelper::arrayUnique($context, $args[0], $flags);
    }

    private static function resolveJitFlags(Context $context, JITVariable $flagsArg): int
    {
        return self::normalizeFlags(
            VmInternalCompare::resolveJitSortFlags($context, $flagsArg, 'array_unique')
        );
    }
}
