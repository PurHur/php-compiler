<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\Vararg;

/**
 * Skip uncaught-trace frame push/pop and after-call throw-pending checks for
 * user functions whose CFG cannot throw (#36386).
 *
 * php-src always records EG(current_execute_data) frames; when a function body
 * has no {@see OpCode::TYPE_THROW}, no {@see OpCode::TYPE_NEW}, no includes, and
 * only calls to itself or other proven no-throw user functions (leaf recursion
 * like {@code fibo_r}, call chains like {@code top→mid→leaf}, leaf methods
 * like {@code Node::bump}, or same-class method chains like
 * {@code A::top→A::mid→A::leaf}) — the AOT frames would never appear on an
 * uncaught trace — paying {@code phpc_ex_stack_push/pop} +
 * {@code phpc_jit_has_throw_pending} on every edge is pure overhead.
 *
 * Analyze at enqueue time (before {@see \PHPCompiler\JIT::runQueue}), not only when
 * the body is lowered: `{main}` resolves method calls while callees are still
 * queued, so a body-time record is too late for call-site elision.
 *
 * A fixpoint at the start of {@see refineFixpoint} upgrades callers once their
 * callees become proven (declaration order must not matter).
 */
final class NoThrowCallElision
{
    /**
     * Record whether {@code $funcLc} is safe to call without exception-stack /
     * pending-throw instrumentation.
     */
    public static function analyzeAndRecord(Context $context, Block $entry, string $funcLc): void
    {
        $funcLc = strtolower($funcLc);
        if ('' === $funcLc || '{main}' === $funcLc) {
            return;
        }
        $context->noThrowAnalyzeBlocks[$funcLc] = $entry;
        if (!empty($context->noThrowUserFunctions[$funcLc])) {
            return;
        }
        $context->noThrowUserFunctions[$funcLc] = self::bodyIsNoThrowCalleeGraph(
            $entry,
            $funcLc,
            $context
        );
    }

    /**
     * Re-evaluate bodies that failed only because callees were not yet proven.
     * Call once all user functions are enqueued, before lowering call sites.
     */
    public static function refineFixpoint(Context $context): void
    {
        $pending = $context->noThrowAnalyzeBlocks;
        if ([] === $pending) {
            return;
        }
        $limit = count($pending) + 2;
        for ($pass = 0; $pass < $limit; ++$pass) {
            $changed = false;
            foreach ($pending as $funcLc => $entry) {
                if (!empty($context->noThrowUserFunctions[$funcLc])) {
                    continue;
                }
                if (self::bodyIsNoThrowCalleeGraph($entry, $funcLc, $context)) {
                    $context->noThrowUserFunctions[$funcLc] = true;
                    $changed = true;
                }
            }
            if (!$changed) {
                return;
            }
        }
    }

    public static function calleeIsNoThrow(Context $context, Call $toCall): bool
    {
        if (!($toCall instanceof Native || $toCall instanceof Vararg)) {
            return false;
        }
        $name = strtolower((string) $toCall->name);
        if ('' === $name) {
            return false;
        }
        if (!empty($context->noThrowUserFunctions[$name])) {
            return true;
        }
        // `{main}` lowers call sites before runQueue; reverse declaration order
        // (caller before callee) needs a lazy fixpoint so mid/top upgrade after
        // leaf is proven (#36386 call chains).
        if ([] !== $context->noThrowAnalyzeBlocks) {
            self::refineFixpoint($context);
        }

        return !empty($context->noThrowUserFunctions[$name]);
    }

    /**
     * True when the body cannot throw and every FUNCCALL target is self or an
     * already-proven no-throw user function.
     */
    private static function bodyIsNoThrowCalleeGraph(
        Block $entry,
        string $selfLc,
        Context $context
    ): bool {
        $seen = [];
        $stack = [$entry];
        while ([] !== $stack) {
            /** @var Block $block */
            $block = array_pop($stack);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            foreach ($block->opCodes as $op) {
                $type = $op->type;
                if (OpCode::TYPE_FUNCDEF === $type || OpCode::TYPE_CLOSURE === $type) {
                    // Nested declarations are other functions — do not attribute their
                    // bodies to this one, and do not walk into them.
                    continue;
                }
                if (OpCode::TYPE_THROW === $type
                    || OpCode::TYPE_NEW === $type
                    || OpCode::TYPE_INCLUDE === $type
                    || OpCode::TYPE_STATICCALL_INIT === $type
                    || OpCode::TYPE_FROM_CALLABLE === $type
                ) {
                    return false;
                }
                if (OpCode::TYPE_FUNCCALL_INIT === $type) {
                    if (!empty($op->funcCallDynamic)) {
                        return false;
                    }
                    $nameOp = $block->getOperand($op->arg1);
                    if (!$nameOp instanceof Operand\Literal) {
                        return false;
                    }
                    $calleeLc = strtolower((string) $nameOp->value);
                    if (!self::isAllowedNoThrowCallee($context, $selfLc, $calleeLc)) {
                        return false;
                    }
                }
                if (OpCode::TYPE_METHODCALL_INIT === $type) {
                    // Same-class `$this->leaf()` chains: allow when the target method
                    // is already proven no-throw (fixpoint upgrades mid after leaf).
                    // Cross-class bare-name matches are rejected — two classes can
                    // share a method name with different throw behaviour (#36386).
                    $methodLc = self::literalMethodNameLc($block, $op->arg2);
                    if (null === $methodLc
                        || !self::isAllowedNoThrowMethodCallee($context, $selfLc, $methodLc)
                    ) {
                        return false;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $child) {
                    if ($child instanceof Block) {
                        $stack[] = $child;
                    }
                }
            }
            foreach ($block->blocks as $child) {
                if ($child instanceof Block) {
                    $stack[] = $child;
                }
            }
        }

        return true;
    }

    private static function isAllowedNoThrowCallee(
        Context $context,
        string $selfLc,
        string $calleeLc
    ): bool {
        // Self-recursion uses the bare method name in CFG; scoped
        // `Class::method` keys must still match (#36386 leaf methods).
        if ($calleeLc === $selfLc || $calleeLc === self::bareName($selfLc)) {
            return true;
        }
        if (!empty($context->noThrowUserFunctions[$calleeLc])) {
            return true;
        }
        // Scoped key vs bare CFG name (Class::leaf ↔ leaf).
        $bare = self::bareName($calleeLc);
        if ($bare !== $calleeLc && !empty($context->noThrowUserFunctions[$bare])) {
            return true;
        }
        foreach ($context->noThrowUserFunctions as $knownLc => $ok) {
            if (!$ok) {
                continue;
            }
            if (self::bareName($knownLc) === $calleeLc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Instance method callees are keyed {@code class::method}. Prefer the
     * caller's class scope so {@code B::leaf} throwing does not unlock
     * {@code A::mid}'s {@code $this->leaf()} when only {@code A::leaf} is safe.
     */
    private static function isAllowedNoThrowMethodCallee(
        Context $context,
        string $selfLc,
        string $methodLc
    ): bool {
        if ($methodLc === self::bareName($selfLc)) {
            return true;
        }
        $class = self::classPrefix($selfLc);
        if ('' !== $class) {
            $scoped = $class.'::'.$methodLc;
            if (!empty($context->noThrowUserFunctions[$scoped])) {
                return true;
            }
        }
        if (!empty($context->noThrowUserFunctions[$methodLc])) {
            return true;
        }

        return false;
    }

    private static function literalMethodNameLc(Block $block, ?int $nameSlot): ?string
    {
        if (null === $nameSlot) {
            return null;
        }
        $nameOp = $block->getOperand($nameSlot);
        if (!$nameOp instanceof Operand\Literal && isset($block->constants[$nameSlot])) {
            $nameOp = new Operand\Literal($block->constants[$nameSlot]->toString());
        }
        if (!$nameOp instanceof Operand\Literal) {
            return null;
        }
        $raw = is_string($nameOp->value) ? $nameOp->value : (string) $nameOp->value;
        if ('' === $raw) {
            return null;
        }

        return strtolower($raw);
    }

    private static function bareName(string $scopedLc): string
    {
        $pos = strrpos($scopedLc, '::');
        if (false === $pos) {
            return $scopedLc;
        }

        return substr($scopedLc, $pos + 2);
    }

    private static function classPrefix(string $scopedLc): string
    {
        $pos = strrpos($scopedLc, '::');
        if (false === $pos) {
            return '';
        }

        return substr($scopedLc, 0, $pos);
    }
}
