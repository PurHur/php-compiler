<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * levenshtein() — edit distance between two strings (subset of PHP; issue #2406).
 *
 * VM: {@see VmString::levenshtein()}; JIT/AOT: {@see JitLevenshtein}.
 */
final class levenshtein extends Internal
{
    public function __construct()
    {
        parent::__construct('levenshtein');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'levenshtein() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'levenshtein() expects at most 5 arguments, %d given',
                $argc
            ));
        }
        $a = self::vmStringArg($frame, 0, 'string1');
        $b = self::vmStringArg($frame, 1, 'string2');
        $ins = 1;
        $rep = 1;
        $del = 1;
        if ($argc >= 3) {
            $ins = self::vmCostArg($frame, 2, 'insertion_cost');
        }
        if ($argc >= 4) {
            $rep = self::vmCostArg($frame, 3, 'replacement_cost');
        }
        if ($argc >= 5) {
            $del = self::vmCostArg($frame, 4, 'deletion_cost');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::levenshtein($a, $b, $ins, $rep, $del));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'levenshtein() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'levenshtein() expects at most 5 arguments, %d given',
                $argc
            ));
        }
        $i64 = $context->getTypeFromString('int64');
        $ins = $i64->constInt(1, false);
        $rep = $i64->constInt(1, false);
        $del = $i64->constInt(1, false);
        if ($argc >= 3) {
            $ins = self::jitCostArg($context, $args[2], 3, 'insertion_cost');
        }
        if ($argc >= 4) {
            $rep = self::jitCostArg($context, $args[3], 4, 'replacement_cost');
        }
        if ($argc >= 5) {
            $del = self::jitCostArg($context, $args[4], 5, 'deletion_cost');
        }

        return JitLevenshtein::invoke(
            $context,
            self::jitStringArg($context, $args[0], 1, 'string1'),
            self::jitStringArg($context, $args[1], 2, 'string2'),
            $ins,
            $rep,
            $del
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireString($frame, $argIndex, 'levenshtein', $paramName)->toString();
        }

        return VmString::coerceStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            'levenshtein',
            $argIndex,
            $paramName
        );
    }

    private static function vmCostArg(Frame $frame, int $argIndex, string $paramName): int
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireInt($frame, $argIndex, 'levenshtein', $paramName)->toInt();
        }

        return VmMath::parseIntBuiltinArg(
            $frame->calledArgs[$argIndex]->resolveIndirect(),
            'levenshtein',
            $argIndex + 1,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): Value {
        JitInternalStrictArg::requireString($context, $arg, 'levenshtein', $paramName, $argNumber);

        return JitStringBuiltinArg::lower(
            $context,
            $arg,
            'levenshtein',
            $argNumber - 1,
            $paramName
        );
    }

    private static function jitCostArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): Value {
        JitInternalStrictArg::requireInt($context, $arg, 'levenshtein', $paramName, $argNumber);

        return JitLongArg::lower($context, $arg, sprintf('levenshtein() argument #%d', $argNumber));
    }
}
