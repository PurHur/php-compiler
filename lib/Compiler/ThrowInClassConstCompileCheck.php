<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\FuncCall;
use PHPCfg\Op\Expr\MethodCall;
use PHPCfg\Op\Expr\StaticCall;
use PHPCfg\Op\Expr\Throw_;
use PHPCfg\Script;

/**
 * Reject disallowed expressions in class/enum constant initializers (#6580, #6843).
 *
 * php-src: Zend/zend_ast.c — zend_ast_validate(); Zend/zend_compile.c zend_compile_const_expr().
 * Distinct from runtime throw expressions (#3802) and property/param defaults (#3803).
 */
final class ThrowInClassConstCompileCheck
{
    public const MESSAGE = 'Constant expression contains invalid operations';

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
            if ($op instanceof Throw_
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
}
