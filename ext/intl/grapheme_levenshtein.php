<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * grapheme_levenshtein() — grapheme-cluster edit distance (php-src ext/intl/php_intl.stub.php; #27591).
 *
 * Registered only when {@see \PHPCompiler\CompilerVersion::supportsGraphemeLevenshtein()} (PROFILE≥8.5)
 * and host ext/intl is loaded. VM: {@see VmGrapheme::levenshtein()}; JIT: compile-time fold via
 * {@see JitGrapheme::tryLevenshteinFold()}. Optional $locale is accepted for arity/Reflection parity;
 * collator-aware equality remains the default canonical path (empty locale).
 */
final class grapheme_levenshtein extends Internal
{
    /** php-src: cost must be > 0 and <= UINT_MAX/4. */
    private const COST_MAX = 1073741823;

    public function __construct()
    {
        parent::__construct('grapheme_levenshtein');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'grapheme_levenshtein', 2, 6);
        $string1 = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_levenshtein',
            0,
            'string1'
        );
        $string2 = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_levenshtein',
            1,
            'string2'
        );
        $argc = \count($frame->calledArgs);
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
        if ($argc >= 6) {
            // Accept locale for stub arity; collator path uses default canonical equality.
            VmString::coerceStringBuiltinArg(
                $frame->calledArgs[5],
                'grapheme_levenshtein',
                5,
                'locale'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmGrapheme::levenshtein($string1, $string2, $ins, $rep, $del);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'grapheme_levenshtein', 2, 6)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $folded = JitGrapheme::tryLevenshteinFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_levenshtein', 0, 'string1');
        JitStringBuiltinArg::lower($context, $args[1], 'grapheme_levenshtein', 1, 'string2');
        if (isset($args[2])) {
            JitInternalStrictArg::requireInt($context, $args[2], 'grapheme_levenshtein', 'insertion_cost', 3);
            JitLongArg::lower($context, $args[2], 'grapheme_levenshtein() argument #3');
        }
        if (isset($args[3])) {
            JitInternalStrictArg::requireInt($context, $args[3], 'grapheme_levenshtein', 'replacement_cost', 4);
            JitLongArg::lower($context, $args[3], 'grapheme_levenshtein() argument #4');
        }
        if (isset($args[4])) {
            JitInternalStrictArg::requireInt($context, $args[4], 'grapheme_levenshtein', 'deletion_cost', 5);
            JitLongArg::lower($context, $args[4], 'grapheme_levenshtein() argument #5');
        }
        if (isset($args[5])) {
            JitStringBuiltinArg::lower($context, $args[5], 'grapheme_levenshtein', 5, 'locale');
        }

        throw new \LogicException(
            'grapheme_levenshtein() JIT runtime lowering is deferred; use VM or compile-time literals (#27591)'
        );
    }

    private static function vmCostArg(Frame $frame, int $argIndex, string $paramName): int
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            $cost = InternalStrictArg::requireInt($frame, $argIndex, 'grapheme_levenshtein', $paramName)->toInt();
        } else {
            $cost = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[$argIndex]->resolveIndirect(),
                'grapheme_levenshtein',
                $argIndex + 1,
                $paramName
            );
        }
        self::assertCostInRange($cost, $argIndex + 1);

        return $cost;
    }

    private static function assertCostInRange(int $cost, int $argNumber): void
    {
        if ($cost <= 0 || $cost > self::COST_MAX) {
            throw new \ValueError(\sprintf(
                'grapheme_levenshtein(): Argument #%d must be greater than 0 and less than or equal to %d',
                $argNumber,
                self::COST_MAX
            ));
        }
    }
}
