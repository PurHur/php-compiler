<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\New_;
use PHPCfg\Script;

/**
 * Reject `new` in class **constant** initializers (#6549, #9373).
 *
 * php-src (8.2 reference): zend_compile_const_expr() rejects all `new` with
 * "New expressions are not supported in this context". PHP 8.3+ may allow
 * `new Class()` in constants (#9116); gate there when the language level rises.
 *
 * Property defaults may use `new` with or without `()` per PHP 8.1+ (#3391, #5362).
 */
final class NewWithoutParensCompileCheck
{
    public const MESSAGE = 'New expressions are not supported in this context';

    public static function validate(Script $script, ?string $sourceCode = null): void
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

    private function walkClassLike(
        Op\Stmt\Class_|Op\Stmt\Interface_|Op\Stmt\Trait_|Op\Stmt\Enum_ $class
    ): void {
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
            if ($op instanceof New_) {
                throw new \CompileError(self::MESSAGE);
            }
            foreach ($op->getSubBlocks() as $sub) {
                if (null !== $sub && property_exists($sub, 'children') && is_array($sub->children)) {
                    $this->walkOps($sub->children);
                }
            }
        }
    }
}
