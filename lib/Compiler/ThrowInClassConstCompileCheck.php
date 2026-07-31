<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Op;
use PHPCfg\Op\Expr\ArrowFunction;
use PHPCfg\Op\Expr\Assign;
use PHPCfg\Op\Expr\BinaryOp\Identical;
use PHPCfg\Op\Expr\Cast;
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
use PHPCompiler\CompilerVersion;

/**
 * Reject disallowed expressions in constant initializers (#6580, #6843, #8809, #24904, #24905, #24947, #25839).
 *
 * php-src: Zend/zend_ast.c — zend_ast_validate(); Zend/zend_compile.c zend_compile_const_expr().
 * Distinct from runtime throw expressions (#3802) and property/param defaults (#3803).
 *
 * {@code match} is never a constant expression (no ZEND_AST_MATCH in the allow-list); php-cfg
 * lowers it to a result-seed Assign plus Identical/JumpIf (or default-only Assign+Jump).
 *
 * Silence ({@code ZEND_AST_SILENCE} → php-cfg {@see ErrorSuppressBlock}) is always outside the
 * allow-list (#24905). Scalar/(array) casts ({@code ZEND_AST_CAST}) are allowed on PHP 8.5+
 * ({@see CompilerVersion::supportsCastsInConstantExpressions}); (object)/(void)/(unset) stay
 * rejected. On ≤8.4 every *user* cast remains invalid (#24905). php-cfg's synthetic
 * {@see Cast\Bool_} on the long arm of {@code &&}/{@code ||} is not a ZEND_AST_CAST — allow it
 * so class-const logical expressions match Zend (#25839 / re-#17229).
 */
final class ThrowInClassConstCompileCheck
{
    public const MESSAGE = 'Constant expression contains invalid operations';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof ConstTerminal) {
                $check->walkConstValueBlock($child->valueBlock);
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
                $this->walkConstValueBlock($stmt->valueBlock);
            }
        }
    }

    /**
     * After php-cfg Simplifier, {@code @} may collapse so {@see ConstTerminal::$valueBlock}
     * itself is an {@see ErrorSuppressBlock} (no outer Jump remains).
     */
    private function walkConstValueBlock(?Block $valueBlock): void
    {
        if (null === $valueBlock) {
            return;
        }
        if ($valueBlock instanceof ErrorSuppressBlock) {
            throw new \CompileError(self::MESSAGE);
        }
        $this->walkOps($valueBlock->children ?? []);
    }

    /**
     * @param list<Op> $ops
     * @param bool     $allowShortCircuitBoolCast php-cfg inserts {@see Cast\Bool_} on &&/|| (#25839)
     */
    private function walkOps(array $ops, bool $allowShortCircuitBoolCast = false): void
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
            if ($op instanceof Cast) {
                $shortCircuitTail = $allowShortCircuitBoolCast
                    && self::isShortCircuitBoolCastTail($ops, $op);
                if (!$shortCircuitTail && !self::isAllowedConstExprCast($op)) {
                    throw new \CompileError(self::MESSAGE);
                }
            }
            if ($op instanceof Jump && $op->target instanceof ErrorSuppressBlock) {
                throw new \CompileError(self::MESSAGE);
            }
            $armAllow = $allowShortCircuitBoolCast
                || ($op instanceof JumpIf && self::isLogicalShortCircuitJumpIf($op));
            foreach ($op->getSubBlocks() as $name) {
                $sub = is_string($name) && property_exists($op, $name) ? $op->{$name} : $name;
                if ($sub instanceof ErrorSuppressBlock) {
                    throw new \CompileError(self::MESSAGE);
                }
                if ($sub instanceof Block && property_exists($sub, 'children') && is_array($sub->children)) {
                    $this->walkOps($sub->children, $armAllow);
                }
            }
        }
    }

    /**
     * php-cfg {@see Parser::parseShortCircuiting}: one arm assigns a bool literal, the other
     * ends with a synthetic {@see Cast\Bool_} before Jump.
     */
    private static function isLogicalShortCircuitJumpIf(JumpIf $jumpIf): bool
    {
        $ifTail = self::branchTailExprBeforeJump($jumpIf->if);
        $elseTail = self::branchTailExprBeforeJump($jumpIf->else);
        if (
            $ifTail instanceof Cast\Bool_
            && $elseTail instanceof Assign
            && $elseTail->expr instanceof Operand\Literal
        ) {
            return true;
        }

        return $elseTail instanceof Cast\Bool_
            && $ifTail instanceof Assign
            && $ifTail->expr instanceof Operand\Literal;
    }

    private static function branchTailExprBeforeJump(?Block $branch): ?Op
    {
        if (null === $branch || !is_array($branch->children) || [] === $branch->children) {
            return null;
        }
        $children = $branch->children;
        $jumpIdx = null;
        foreach ($children as $i => $child) {
            if ($child instanceof Jump) {
                $jumpIdx = $i;
                break;
            }
        }
        if (null === $jumpIdx || 0 === $jumpIdx) {
            return null;
        }
        $tail = $children[$jumpIdx - 1];

        return $tail instanceof Op\Expr ? $tail : null;
    }

    /**
     * Only the trailing synthetic bool coercion is exempt — a user {@code (bool)} on the RHS
     * of {@code &&}/{@code ||} still appears earlier and stays rejected on ≤8.4.
     *
     * @param list<Op> $ops
     */
    private static function isShortCircuitBoolCastTail(array $ops, Cast $cast): bool
    {
        if (!$cast instanceof Cast\Bool_) {
            return false;
        }
        $jumpIdx = null;
        foreach ($ops as $i => $op) {
            if ($op instanceof Jump) {
                $jumpIdx = $i;
                break;
            }
        }
        if (null === $jumpIdx || 0 === $jumpIdx) {
            return false;
        }

        return $ops[$jumpIdx - 1] === $cast;
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

    /**
     * PHP 8.5+ allow-list: (int)/(float)/(string)/(bool)/(array). (object)/(void)/(unset) never.
     *
     * @see CompilerVersion::supportsCastsInConstantExpressions()
     */
    private static function isAllowedConstExprCast(Cast $op): bool
    {
        if (!CompilerVersion::supportsCastsInConstantExpressions()) {
            return false;
        }

        return $op instanceof Cast\Int_
            || $op instanceof Cast\Double
            || $op instanceof Cast\String_
            || $op instanceof Cast\Bool_
            || $op instanceof Cast\Array_;
    }
}
