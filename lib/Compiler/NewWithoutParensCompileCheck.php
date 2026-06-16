<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\New_;
use PHPCfg\Script;

/**
 * Reject bare `new Class` (no constructor parentheses) in class **constant** initializers (#6549).
 *
 * PHP 8.3+ allows `new Class()` in constant expressions (#9116, zend_constants.c).
 * Property defaults may use `new` with or without `()` per PHP 8.1+ (#3391, #5362).
 */
final class NewWithoutParensCompileCheck
{
    public const MESSAGE = 'New expressions are not supported in this context';

    public static function validate(Script $script, ?string $sourceCode = null): void
    {
        NewCtorParens::resetMatchCursor();
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_
                || $child instanceof Op\Stmt\Interface_
                || $child instanceof Op\Stmt\Trait_
                || $child instanceof Op\Stmt\Enum_
            ) {
                $check->walkClassLike($child, $sourceCode);
            }
        }
    }

    private function walkClassLike(
        Op\Stmt\Class_|Op\Stmt\Interface_|Op\Stmt\Trait_|Op\Stmt\Enum_ $class,
        ?string $sourceCode
    ): void {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Terminal\Const_) {
                $this->walkOps($stmt->valueBlock->children ?? [], $sourceCode);
            }
        }
    }

    /**
     * @param list<Op> $ops
     */
    private function walkOps(array $ops, ?string $sourceCode): void
    {
        foreach ($ops as $op) {
            if ($op instanceof New_ && !NewCtorParens::hasCtorParens($op, $sourceCode)) {
                throw new \CompileError(self::MESSAGE);
            }
            foreach ($op->getSubBlocks() as $sub) {
                if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                    $this->walkOps($sub->children, $sourceCode);
                }
            }
        }
    }
}
