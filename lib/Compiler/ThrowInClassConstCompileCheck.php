<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\ArrowFunction;
use PHPCfg\Op\Expr\Assign;
use PHPCfg\Op\Expr\BinaryOp\Identical;
use PHPCfg\Op\Expr\Closure;
use PHPCfg\Op\Expr\FuncCall;
use PHPCfg\Op\Expr\MethodCall;
use PHPCfg\Op\Expr\StaticCall;
use PHPCfg\Op\Expr\Throw_;
use PHPCfg\Op\Stmt\Jump;
use PHPCfg\Op\Stmt\JumpIf;
use PHPCfg\Op\Terminal\Const_ as ConstTerminal;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Reject disallowed expressions in constant initializers (#6580, #6843, #8809, #24904).
 *
 * php-src: Zend/zend_ast.c — zend_ast_validate(); Zend/zend_compile.c zend_compile_const_expr().
 * Distinct from runtime throw expressions (#3802) and property/param defaults (#3803).
 *
 * {@code match} is never a constant expression (no ZEND_AST_MATCH in the allow-list); php-cfg
 * lowers it to a result-seed Assign plus Identical/JumpIf (or default-only Assign+Jump).
 */
final class ThrowInClassConstCompileCheck
{
    public const MESSAGE = 'Constant expression contains invalid operations';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof ConstTerminal) {
                $check->walkOps($child->valueBlock->children ?? []);
                continue;
            }
            if ($child instanceof Op\Stmt\Class_
                || $child instanceof Op\Stmt\Interface_
                || $child instanceof Op\Stmt\Trait_
                || $child instanceof Op\Stmt\Enum_
            ) {
                $check->walkClassLike($child);
            }
        }
    }

    private function walkClassLike(Op\Stmt\Class_|Op\Stmt\Interface_|Op\Stmt\Trait_|Op\Stmt\Enum_ $class): void
    {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Terminal\Const_) {
                $this->walkOps($stmt->valueBlock->children ?? []);
            }
        }
    }

    /**
     * @param list<Op> $ops
     */
    private function walkOps(array $ops): void
    {
        if ($this->looksLikeLoweredMatch($ops)) {
            throw new \CompileError(self::MESSAGE);
        }
        foreach ($ops as $op) {
            if ($op instanceof Throw_
                || $op instanceof Closure
                || $op instanceof ArrowFunction
                || $op instanceof FuncCall
                || $op instanceof MethodCall
                || $op instanceof StaticCall
            ) {
                throw new \CompileError(self::MESSAGE);
            }
            foreach ($op->getSubBlocks() as $sub) {
                if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                    $this->walkOps($sub->children);
                }
            }
        }
    }

    /**
     * php-cfg match lowering vs legal const-expr shapes:
     * - match arms: seed Assign(null|'') + Identical + JumpIf
     * - match default-only: seed Assign(null|'') + value Assign + Jump
     * - ternary with {@code ===}: Identical + JumpIf (no seed Assign)
     * - bare {@code ===}: Identical alone
     *
     * @param list<Op> $ops
     */
    private function looksLikeLoweredMatch(array $ops): bool
    {
        if ([] === $ops || !$ops[0] instanceof Assign || !$this->isMatchResultSeedAssign($ops[0])) {
            return false;
        }

        $hasIdentical = false;
        $hasJumpIf = false;
        $hasJump = false;
        $hasNonSeedAssign = false;
        foreach ($ops as $i => $op) {
            if ($i > 0 && $op instanceof Assign) {
                $hasNonSeedAssign = true;
            }
            if ($op instanceof Identical) {
                $hasIdentical = true;
            }
            if ($op instanceof JumpIf) {
                $hasJumpIf = true;
            }
            if ($op instanceof Jump) {
                $hasJump = true;
            }
        }

        if ($hasIdentical && $hasJumpIf) {
            return true;
        }

        // default-only: match (x) { default => ... } — no Identical arms
        return $hasNonSeedAssign && $hasJump && !$hasIdentical;
    }

    private function isMatchResultSeedAssign(Assign $op): bool
    {
        $expr = $op->expr;
        if (!$expr instanceof Operand\Literal) {
            return false;
        }
        $value = $expr->value;

        return null === $value || '' === $value;
    }
}
