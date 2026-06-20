<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\New_;
use PHPCfg\Op\Stmt\Property;
use PHPCfg\Script;

/**
 * Reject invalid `new` in **static property** initializers (#6549, #9484, #10095).
 *
 * PHP 8.3+ allows `new` in class constant initializers (#10198, RFC new_in_initializers);
 * those are lowered by the compiler and materialized at TYPE_DECLARE_CLASS_CONST (#3196).
 * Static property defaults remain forbidden; instance property/param defaults may use
 * `new` with or without `()` per PHP 8.1+ (#3391, #5362).
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
            if ($stmt instanceof Property && $stmt->static) {
                $this->rejectStaticPropertyDefaultNew($stmt);
            }
        }
    }

    private function rejectStaticPropertyDefaultNew(Property $prop): void
    {
        if (null === $prop->defaultVar && null === $prop->defaultBlock) {
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
     * php-cfg represents `const X = [new C()]` as sibling {@see New_} + {@see Op\Expr\Array_}.
     *
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
}
