<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\CompilerVersion;
use PHPCfg\Op;
use PHPCfg\Op\Expr\New_;
use PHPCfg\Op\Stmt\Property;
use PHPCfg\Script;
use PHPCfg\Op\Terminal\Const_ as ConstTerminal;

/**
 * Reject invalid `new` in class **constant**, **static property**, and **instance property** initializers
 * (#6549, #9484, #10095, #10391, #10693).
 *
 * php-src rejects all `new` in class constant expressions and property default expressions with
 * "New expressions are not supported in this context" (zend_compile_const_expr / zend_compile_property).
 * Constructor parameter defaults (including promoted properties) may still use `new` (#3391 RFC).
 *
 * @see Zend/zend_compile.c — zend_compile_const_expr(), zend_compile_property()
 */
final class NewWithoutParensCompileCheck
{
    public const MESSAGE = 'New expressions are not supported in this context';

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

    private ?string $sourceCode = null;

    private function walkClassLike(
        Op\Stmt\Class_|Op\Stmt\Interface_|Op\Stmt\Trait_|Op\Stmt\Enum_ $class
    ): void {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof ConstTerminal) {
                $this->walkClassConstValue($stmt, $this->sourceCode);
                continue;
            }
            if ($stmt instanceof Property) {
                $this->rejectPropertyDefaultNew($stmt);
            }
        }
    }

    private function walkClassConstValue(ConstTerminal $const, ?string $sourceCode): void
    {
        $children = $const->valueBlock->children ?? [];
        if (!CompilerVersion::supportsClassConstObjectExpressions()) {
            $this->walkOpsRejectAllNew($children);

            return;
        }
        $this->walkClassConstNewExpr($children, $sourceCode, true);
    }

    /**
     * PHP 8.3+ allows a single top-level `new Class(...)` in class constants (#12940, #16878).
     *
     * @param list<Op> $ops
     */
    private function walkClassConstNewExpr(array $ops, ?string $sourceCode, bool $atTopLevel): void
    {
        $requireCtorParens = !CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers();
        $topLevelNewCount = 0;
        foreach ($ops as $op) {
            if (!$op instanceof Op) {
                continue;
            }
            if ($op instanceof New_) {
                if (!$atTopLevel) {
                    throw new \CompileError(self::MESSAGE);
                }
                if ($requireCtorParens && !NewCtorParens::hasCtorParens($op, $sourceCode)) {
                    throw new \CompileError(self::MESSAGE);
                }
                ++$topLevelNewCount;
                continue;
            }
            if ($atTopLevel && $topLevelNewCount > 0) {
                throw new \CompileError(self::MESSAGE);
            }
            $this->walkSubBlocksClassConstNew($op, $sourceCode, false);
        }
        if ($atTopLevel && $topLevelNewCount > 1) {
            throw new \CompileError(self::MESSAGE);
        }
    }

    private function walkSubBlocksClassConstNew(Op $op, ?string $sourceCode, bool $atTopLevel): void
    {
        foreach ($op->getSubBlocks() as $sub) {
            if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                $this->walkClassConstNewExpr($sub->children, $sourceCode, $atTopLevel);
            }
        }
    }

    private function rejectPropertyDefaultNew(Property $prop): void
    {
        if (null === $prop->defaultVar && null === $prop->defaultBlock) {
            return;
        }
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions() && !$prop->static) {
            $requireCtorParens = true;
            if (null !== $prop->defaultBlock && [] !== ($prop->defaultBlock->children ?? [])) {
                $this->walkPropertyDefaultNewExpr($prop->defaultBlock->children, $this->sourceCode, true, $requireCtorParens);

                return;
            }
            if ($prop->defaultVar instanceof New_) {
                if ($requireCtorParens && !NewCtorParens::hasCtorParens($prop->defaultVar, $this->sourceCode)) {
                    throw new \CompileError(self::MESSAGE);
                }

                return;
            }
            if (!$prop->defaultVar instanceof Op) {
                return;
            }
            $this->walkSubBlocksPropertyDefaultNew($prop->defaultVar, $this->sourceCode, true, $requireCtorParens);

            return;
        }
        if (CompilerVersion::supportsStaticPropertyDefaultObjectExpressions() && $prop->static) {
            $requireCtorParens = !CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers();
            if (null !== $prop->defaultBlock && [] !== ($prop->defaultBlock->children ?? [])) {
                $this->walkPropertyDefaultNewExpr($prop->defaultBlock->children, $this->sourceCode, true, $requireCtorParens);

                return;
            }
            if ($prop->defaultVar instanceof New_) {
                if ($requireCtorParens && !NewCtorParens::hasCtorParens($prop->defaultVar, $this->sourceCode)) {
                    throw new \CompileError(self::MESSAGE);
                }

                return;
            }
            if (!$prop->defaultVar instanceof Op) {
                return;
            }
            $this->walkSubBlocksPropertyDefaultNew($prop->defaultVar, $this->sourceCode, true, $requireCtorParens);

            return;
        }
        if (null !== $prop->defaultBlock && [] !== ($prop->defaultBlock->children ?? [])) {
            $this->walkOpsRejectAllNew($prop->defaultBlock->children);

            return;
        }
        if ($prop->defaultVar instanceof New_) {
            throw new \CompileError(self::MESSAGE);
        }
    }

    /**
     * PHP 8.4+ property default: single top-level `new Class` / `new Class(...)` (#18040, #18816).
     *
     * @param list<Op> $ops
     */
    private function walkPropertyDefaultNewExpr(
        array $ops,
        ?string $sourceCode,
        bool $atTopLevel,
        bool $requireCtorParens = true
    ): void {
        $topLevelNewCount = 0;
        foreach ($ops as $op) {
            if (!$op instanceof Op) {
                continue;
            }
            if ($op instanceof New_) {
                if (!$atTopLevel) {
                    throw new \CompileError(self::MESSAGE);
                }
                if ($requireCtorParens && !NewCtorParens::hasCtorParens($op, $sourceCode)) {
                    throw new \CompileError(self::MESSAGE);
                }
                ++$topLevelNewCount;
                continue;
            }
            if ($atTopLevel && $topLevelNewCount > 0) {
                throw new \CompileError(self::MESSAGE);
            }
            $this->walkSubBlocksPropertyDefaultNew($op, $sourceCode, false, $requireCtorParens);
        }
        if ($atTopLevel && $topLevelNewCount > 1) {
            throw new \CompileError(self::MESSAGE);
        }
    }

    private function walkSubBlocksPropertyDefaultNew(
        Op $op,
        ?string $sourceCode,
        bool $atTopLevel,
        bool $requireCtorParens = true
    ): void {
        foreach ($op->getSubBlocks() as $sub) {
            if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                $this->walkPropertyDefaultNewExpr($sub->children, $sourceCode, $atTopLevel, $requireCtorParens);
            }
        }
    }

    /**
     * php-cfg represents `const X = [new C()]` as sibling {@see New_} + {@see Op\Expr\Array_}.
     *
     * @param list<Op> $ops
     */
    private function walkOpsRejectAllNew(array $ops): void
    {
        foreach ($ops as $op) {
            if (!$op instanceof Op) {
                continue;
            }
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
}
