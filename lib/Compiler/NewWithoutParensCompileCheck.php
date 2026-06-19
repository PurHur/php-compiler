<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\New_;
use PHPCfg\Script;
use PHPCompiler\CompilerVersion;

/**
 * Reject invalid `new` in class **constant** initializers (#6549, #9484, #9804, #9850).
 *
 * php-src 8.3+ allows top-level `new Class(...)` with constant-expression args in class
 * constants. Bare `new Class` without `()` and `new` feeding array literals remain invalid
 * (php-cfg hoists array-element `new` as a sibling of {@see Op\Expr\Array_}).
 * Property/param defaults may use `new` with or without `()` per PHP 8.1+ (#3391, #5362).
 *
 * @see Zend/zend_compile.c — zend_compile_const_expr()
 */
final class NewWithoutParensCompileCheck
{
    public const MESSAGE = 'New expressions are not supported in this context';

    private const CTX_ROOT = 0;
    private const CTX_NEW_ARG = 1;

    private ?string $sourceCode = null;

    public static function validate(Script $script, ?string $sourceCode = null): void
    {
        NewCtorParens::resetMatchCursor();
        $check = new self();
        $check->sourceCode = $sourceCode;
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_
                || $child instanceof Op\Stmt\Interface_
                || $child instanceof Op\Stmt\Trait_
                || $child instanceof Op\Stmt\Enum_
            ) {
                $check->walkClassLike($child);
            }
        }
    }

    private function walkClassLike(
        Op\Stmt\Class_|Op\Stmt\Interface_|Op\Stmt\Trait_|Op\Stmt\Enum_ $class
    ): void {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Terminal\Const_) {
                $ops = $stmt->valueBlock->children ?? [];
                if ($this->classConstValueIsNewExpression($ops)) {
                    $this->walkNewArgOps($ops[0], self::CTX_ROOT);
                } else {
                    $this->walkOpsRejectAllNew($ops);
                }
            }
        }
    }

    /**
     * php-cfg represents `const X = [new C()]` as sibling {@see New_} + {@see Op\Expr\Array_}.
     *
     * @param list<Op> $ops
     */
    private function classConstValueIsNewExpression(array $ops): bool
    {
        if (!CompilerVersion::supportsClassConstObjectExpressions()) {
            return false;
        }
        if (1 !== count($ops) || !$ops[0] instanceof New_) {
            return false;
        }

        return true;
    }

    /**
     * @param list<Op> $ops
     */
    private function walkOpsRejectAllNew(array $ops): void
    {
        foreach ($ops as $op) {
            if ($op instanceof New_) {
                throw new \CompileError(self::MESSAGE);
            }
            $this->walkSubBlocksRejectAllNew($op);
        }
    }

    private function walkSubBlocksRejectAllNew(Op $op): void
    {
        foreach ($op->getSubBlocks() as $sub) {
            if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                $this->walkOpsRejectAllNew($sub->children);
            }
        }
    }

    private function walkNewArgOps(Op $op, int $context): void
    {
        if ($op instanceof New_) {
            if (!NewCtorParens::hasCtorParens($op, $this->sourceCode)) {
                throw new \CompileError(self::MESSAGE);
            }
        }
        foreach ($op->getSubBlocks() as $sub) {
            if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                $this->walkNewArgOpsTree($sub->children);
            }
        }
    }

    /**
     * @param list<Op> $ops
     */
    private function walkNewArgOpsTree(array $ops): void
    {
        foreach ($ops as $op) {
            $this->walkNewArgOps($op, self::CTX_NEW_ARG);
        }
    }
}
