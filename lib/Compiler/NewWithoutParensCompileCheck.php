<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\New_;
use PHPCfg\Script;

/**
 * Reject bare `new Class` (no constructor parentheses) in class **constant** initializers (#6549).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_expr(); Zend/zend_ast.c validation.
 * Property defaults may use `new` with or without `()` per PHP 8.1+ (#3391, #5362).
 */
final class NewWithoutParensCompileCheck
{
    public const MESSAGE = 'New expressions are not supported in this context';

    public static function validate(Script $script): void
    {
        $check = new self();
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
        foreach ($ops as $op) {
            if ($op instanceof New_ && !$this->newHasCtorParens($op)) {
                throw new \CompileError(self::MESSAGE);
            }
            foreach ($op->getSubBlocks() as $sub) {
                if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                    $this->walkOps($sub->children);
                }
            }
        }
    }

    private function newHasCtorParens(New_ $op): bool
    {
        if ($op->hasAttribute('newHasCtorParens')) {
            return (bool) $op->getAttribute('newHasCtorParens');
        }

        // Without php-cfg #6549 patch, empty-arg `new Foo()` is indistinguishable from bare `new Foo`.
        // Class constants cannot use `new` at all; treat missing attribute as rejection.
        return false;
    }
}
