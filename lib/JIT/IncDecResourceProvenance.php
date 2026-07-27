<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Prove that a native long cannot be a resource handle, so ++/-- can skip its guard (#23483).
 *
 * This compiler stores resource handles *as* native longs, and php-types has no resource type at
 * all — `Type::TYPE_LONG` covers both an `int` and an open stream handle. So `++$x` on a
 * TYPE_NATIVE_LONG genuinely cannot tell a loop counter from the result of `fopen()`, and
 * {@see \PHPCompiler\JIT::guardIncDecResourceOperand()} conservatively guards every single ++/--
 * with `__compiler_is_resource`.
 *
 * That guard is not cheap: it calls into {@see \PHPCompiler\ext\standard\StreamLifecycleJitHelper}
 * which walks up to four separate handle registries. In
 * `for ($i = 0; $i < 1000000; ++$i) { ++$a; }` that is two such calls per iteration, and measurably
 * ~92% of the loop's runtime (135ms -> 11ms on build/micro/m_loop.php once elided). It also blocks
 * every LLVM optimisation, because an opaque call in the loop body stops the counter allocas from
 * being promoted out of memory.
 *
 * Resources are only ever *introduced* by a small set of builtins (fopen, opendir, proc_open, ...).
 * A value flowing from an integer literal or from arithmetic therefore cannot be one, and the guard
 * is dead code there. This walks the php-cfg producers of the read operand to prove exactly that.
 *
 * The analysis is deliberately conservative: anything it does not recognise — a call, a parameter,
 * a property or array read — answers "unknown" and keeps the guard. Only the listed
 * value-producing ops, which cannot yield a resource under any input, allow it to be dropped.
 *
 * Also used to skip resource-handle checks in int→string lowering (#23811).
 */
final class IncDecResourceProvenance
{
    /**
     * Ops whose result is a number, string, bool, array or object — never a resource handle.
     *
     * Coalesce is deliberately absent: `$x ?? fopen(...)` produces whatever the right side does.
     * Assign and Phi are handled separately because they forward another operand's provenance.
     */
    private const NON_RESOURCE_OPS = [
        Op\Expr\BinaryOp\Plus::class => true,
        Op\Expr\BinaryOp\Minus::class => true,
        Op\Expr\BinaryOp\Mul::class => true,
        Op\Expr\BinaryOp\Div::class => true,
        Op\Expr\BinaryOp\Mod::class => true,
        Op\Expr\BinaryOp\Pow::class => true,
        Op\Expr\BinaryOp\ShiftLeft::class => true,
        Op\Expr\BinaryOp\ShiftRight::class => true,
        Op\Expr\BinaryOp\BitwiseAnd::class => true,
        Op\Expr\BinaryOp\BitwiseOr::class => true,
        Op\Expr\BinaryOp\BitwiseXor::class => true,
        Op\Expr\BinaryOp\Concat::class => true,
        Op\Expr\BinaryOp\Equal::class => true,
        Op\Expr\BinaryOp\NotEqual::class => true,
        Op\Expr\BinaryOp\Identical::class => true,
        Op\Expr\BinaryOp\NotIdentical::class => true,
        Op\Expr\BinaryOp\Greater::class => true,
        Op\Expr\BinaryOp\GreaterOrEqual::class => true,
        Op\Expr\BinaryOp\Smaller::class => true,
        Op\Expr\BinaryOp\SmallerOrEqual::class => true,
        Op\Expr\BinaryOp\Spaceship::class => true,
        Op\Expr\BinaryOp\LogicalXor::class => true,
        Op\Expr\UnaryMinus::class => true,
        Op\Expr\UnaryPlus::class => true,
        Op\Expr\BitwiseNot::class => true,
        Op\Expr\BooleanNot::class => true,
        Op\Expr\PreInc::class => true,
        Op\Expr\PostInc::class => true,
        Op\Expr\PreDec::class => true,
        Op\Expr\PostDec::class => true,
        Op\Expr\ConcatList::class => true,
        Op\Expr\Array_::class => true,
        Op\Expr\Cast\Int_::class => true,
        Op\Expr\Cast\Double::class => true,
        Op\Expr\Cast\String_::class => true,
        Op\Expr\Cast\Bool_::class => true,
        Op\Expr\Cast\Array_::class => true,
    ];

    /** Bound on the producer walk, so a pathological CFG cannot cost more than the guard saves. */
    private const MAX_VISITS = 64;

    public static function cannotBeResource(?Operand $op): bool
    {
        if (!$op instanceof Operand) {
            return false;
        }
        $seen = [];
        $budget = self::MAX_VISITS;

        return self::operandIsSafe($op, $seen, $budget);
    }

    /**
     * Like {@see cannotBeResource()} but peels php-cfg {@see Operand\Temporary} wrappers first.
     * Used for int→string lowering where concat operands are often temporaries (#23811).
     */
    public static function cannotBeResourceForString(?Operand $op): bool
    {
        while ($op instanceof Operand\Temporary && $op->original instanceof Operand) {
            $op = $op->original;
        }

        return self::cannotBeResource($op);
    }

    /**
     * @param array<int, true> $seen
     */
    private static function operandIsSafe(Operand $op, array &$seen, int &$budget): bool
    {
        if (--$budget < 0) {
            return false;
        }
        $id = spl_object_id($op);
        if (isset($seen[$id])) {
            // Loop back-edge. Resource-ness can only be *introduced* by a producing op, and a cycle
            // introduces nothing, so this edge carries no counter-example: the remaining edges of
            // the phi still have to prove the property on their own.
            return true;
        }
        $seen[$id] = true;

        if ($op instanceof Operand\Literal) {
            // Literals are parser-level scalars; a resource has no literal syntax.
            return true;
        }

        $producers = $op->ops ?? [];
        if ([] === $producers) {
            // No producer in this CFG — a parameter, a bound closure var, or an operand written
            // somewhere the analysis cannot see. Keep the guard.
            return false;
        }

        foreach ($producers as $producer) {
            if (!self::producerIsSafe($producer, $seen, $budget)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, true> $seen
     */
    private static function producerIsSafe(Op $producer, array &$seen, int &$budget): bool
    {
        if (--$budget < 0) {
            return false;
        }
        if (isset(self::NON_RESOURCE_OPS[$producer::class])) {
            return true;
        }
        if ($producer instanceof Op\Phi) {
            foreach ($producer->vars as $var) {
                if (!$var instanceof Operand || !self::operandIsSafe($var, $seen, $budget)) {
                    return false;
                }
            }

            return true;
        }
        if ($producer instanceof Op\Expr\Assign) {
            $expr = $producer->expr ?? null;

            return $expr instanceof Operand && self::operandIsSafe($expr, $seen, $budget);
        }

        return false;
    }
}
