<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;

/**
 * compileBlock + statement ticks / echo-arg prelude helpers (#36387).
 *
 * Companion to {@see CompileOpsAndStringDimCfgSplit} (compileOps + string-dim
 * CFG splits). Tick emission and echo-arg prelude detection stay here so gen-0
 * split-TU can hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c
 * statement tick / echo lowering — move-only; no behavior change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait CompileOpsTicksAndCfgSplit
{

    protected function compileBlock(Block $block) {
        if (null !== $block->orig && $this->isErrorSuppressEndBlock($block->orig)) {
            $block->addOpCode(new OpCode(OpCode::TYPE_END_SILENCE));
        }
        $this->compileOps($block->orig->children, $block);
        // Do not auto-LEAVE at CFG block edges: file-level declare(ticks=N) and braced
        // bodies that span loops/jumps must keep the interval across successor blocks.
        // Braced scopes emit LeaveTickInterval explicitly from php-cfg (#22840, #23486).
    }

    private function emitTicksBeforeStatementIfNeeded(Op $op, Block $block, array $ops, int $index): void
    {
        if ($this->activeTickInterval <= 0) {
            return;
        }
        if ($op instanceof Op\Terminal\SetTickInterval || $op instanceof Op\Terminal\LeaveTickInterval) {
            return;
        }
        // for ($i=0; $i<n; $i++) init/increment exprs are not Zend statement boundaries (#23486, #25621).
        if ($op->hasAttribute('for_loop_increment') && $op->getAttribute('for_loop_increment')) {
            return;
        }
        if ($op->hasAttribute('for_loop_init') && $op->getAttribute('for_loop_init')) {
            return;
        }
        if (
            $op instanceof Op\Stmt\Function_
            || $op instanceof Op\Stmt\Class_
            || $op instanceof Op\Stmt\Interface_
            || $op instanceof Op\Stmt\Trait_
            || $op instanceof Op\Stmt\Enum_
            || $op instanceof Op\Stmt\Jump
            || $op instanceof Op\Stmt\JumpIf
            || $op instanceof Op\Terminal\Const_
            || $op instanceof Op\Terminal\Return_
        ) {
            return;
        }
        // php-cfg lowers `$x += 1` to BinaryOp + Assign — only the Assign is tickable (#22840).
        if ($op instanceof Op\Expr\BinaryOp) {
            return;
        }
        // echo "a$b" → ConcatList + Echo. Zend ticks at the statement start (before
        // interpolation). Tick before ConcatList; skip the following Echo (#23486).
        if ($op instanceof Op\Expr\ConcatList) {
            $next = $ops[$index + 1] ?? null;
            if ($next instanceof Op\Terminal && 'Terminal_Echo' === $next->getType()) {
                $block->addOpCode(new OpCode(OpCode::TYPE_TICKS));
            }

            return;
        }
        if ($op instanceof Op\Expr\Closure) {
            return;
        }
        // php-src places ZEND_TICKS before each ECHO opcode. `echo $a, $b` and
        // `echo "a"; echo "b"` both lower to consecutive Terminal_Echo — tick each
        // fragment. Skip only the Echo that follows ConcatList (already ticked) (#30010).
        if ($op instanceof Op\Terminal && 'Terminal_Echo' === $op->getType()) {
            $prev = $ops[$index - 1] ?? null;
            if ($prev instanceof Op\Expr\ConcatList) {
                return;
            }
        }
        // Arg evaluation feeding a following Echo (FuncCall/BinaryOp/…) is not a
        // separate Zend statement boundary — ZEND_TICKS sits on the ECHO (#30010).
        if ($this->isEchoArgEvaluationPrelude($op, $ops, $index)) {
            return;
        }
        $block->addOpCode(new OpCode(OpCode::TYPE_TICKS));
    }

    /**
     * True when $op only evaluates an argument of a following Terminal_Echo.
     *
     * Mirrors php-src: ZEND_TICKS is emitted with each ECHO, not with the arg-setup
     * ops php-cfg materializes ahead of it (`echo strtoupper("a"), "b"` / `echo foo(bar())`).
     *
     * Call-arg site clones often break Temporary identity (#8560), so this uses a
     * same-startLine window: producers sharing the echo statement's line are preludes,
     * except `foo(); echo …` where the call result is unused by the following Echo.
     *
     * @param Op[] $ops
     */
    private function isEchoArgEvaluationPrelude(Op $op, array $ops, int $index): bool
    {
        if (!($op instanceof Op\Expr) || !property_exists($op, 'result') || null === $op->result) {
            return false;
        }
        // Statement-level assign (`$a = 1; echo $a`) must tick; only `echo ($a = …)`
        // feeds the Echo expr directly.
        if (
            $op instanceof Op\Expr\Assign
            || $op instanceof Op\Expr\AssignRef
            || $op instanceof Op\Expr\AssignOp
        ) {
            $next = $ops[$index + 1] ?? null;
            if (!($next instanceof Op\Terminal) || 'Terminal_Echo' !== $next->getType()) {
                return false;
            }

            return $this->operandsChainEqual($next->expr, $op->result)
                || $this->operandsReferToSameVariable($next->expr, $op->result);
        }
        if (!$this->isInlineExprCallArgProducer($op)) {
            return false;
        }

        $line = $op->hasAttribute('startLine')
            ? (int) $op->getAttribute('startLine')
            : $op->getLine();
        if ($line <= 0) {
            return false;
        }

        $produced = $op->result;
        $sawEcho = false;
        $resultFeedsEcho = false;
        $immediate = $ops[$index + 1] ?? null;
        $n = \count($ops);
        for ($j = $index + 1; $j < $n; ++$j) {
            $next = $ops[$j];
            $nextLine = $next->hasAttribute('startLine')
                ? (int) $next->getAttribute('startLine')
                : $next->getLine();
            if ($nextLine !== $line) {
                break;
            }
            if ($next instanceof Op\Terminal && 'Terminal_Echo' === $next->getType()) {
                $sawEcho = true;
                if (
                    $this->operandsChainEqual($next->expr, $produced)
                    || $this->operandsReferToSameVariable($next->expr, $produced)
                ) {
                    $resultFeedsEcho = true;
                }
                continue;
            }
            if (
                !($next instanceof Op\Expr)
                || !property_exists($next, 'result')
                || null === $next->result
                || !$this->isInlineExprCallArgProducer($next)
            ) {
                break;
            }
        }
        if (!$sawEcho) {
            return false;
        }
        if ($resultFeedsEcho) {
            return true;
        }
        // Nested call arg: `echo foo(bar())` — bar sits before foo on the echo line.
        if (
            $immediate instanceof Op\Expr
            && $this->isInlineExprCallArgProducer($immediate)
            && !($immediate instanceof Op\Expr\Assign)
            && !($immediate instanceof Op\Expr\AssignRef)
            && !($immediate instanceof Op\Expr\AssignOp)
        ) {
            return true;
        }
        // `foo(); echo "a"` on one line — call result unused by Echo; still tickable.
        if ($immediate instanceof Op\Terminal && 'Terminal_Echo' === $immediate->getType()) {
            return false;
        }

        return true;
    }

}
