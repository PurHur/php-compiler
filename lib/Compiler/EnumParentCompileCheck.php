<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Compile-time check: parent:: / parent type atom when current class scope has no parent
 * (#5410, #7381, #26540).
 *
 * php-src: Zend/zend_compile.c — parent fetch / type resolve when current scope has no parent
 *
 * Traits keep a literal `parent` type keyword (Zend leaves it unresolved until use-site) and
 * are intentionally not scanned here.
 */
final class EnumParentCompileCheck
{
    public const MESSAGE = 'Cannot use "parent" when current class scope has no parent';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Enum_) {
                $check->validateScopeWithoutParent($child, true);
            } elseif ($child instanceof Op\Stmt\Class_ && null === $child->extends) {
                $check->validateScopeWithoutParent($child, true);
            } elseif ($child instanceof Op\Stmt\Interface_) {
                // `parent` type atoms are always illegal on interfaces; `parent::` in const
                // expressions is allowed when the interface extends another (#5410 vs Zend).
                $check->validateScopeWithoutParent($child, [] === $child->extends);
            }
        }
    }

    private function validateScopeWithoutParent(Op\Stmt\ClassLike $class, bool $rejectParentExpr): void
    {
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\ClassMethod) {
                $this->validateMethod($member, $rejectParentExpr);
            } elseif ($member instanceof Op\Stmt\Property) {
                $this->rejectParentInType($member->declaredType, $member);
            } elseif ($member instanceof Op\Terminal\Const_) {
                if (!$rejectParentExpr) {
                    continue;
                }
                $valueBlock = $member->valueBlock;
                if (null !== $valueBlock && [] !== $valueBlock->children) {
                    $this->walkCfg($valueBlock);
                }
            }
        }
    }

    private function validateMethod(Op\Stmt\ClassMethod $method, bool $rejectParentExpr): void
    {
        $func = $method->func;
        $this->rejectParentInType($func->returnType, $method);
        foreach ($func->params as $param) {
            if ($param instanceof Op\Expr\Param) {
                $this->rejectParentInType($param->declaredType, $param);
            }
        }
        if (!$rejectParentExpr) {
            return;
        }
        $cfg = $func->cfg;
        if (null === $cfg || [] === $cfg->children) {
            return;
        }
        $this->walkCfg($cfg);
    }

    private function rejectParentInType(?Op\Type $type, Op $op): void
    {
        if (!PseudoClassTypeHintCompileCheck::containsKeyword($type, 'parent')) {
            return;
        }
        throw new CompileFatal(
            $op->getFile(),
            max(1, $op->getLine()),
            self::MESSAGE
        );
    }

    private function walkCfg(CfgBlock $entry): void
    {
        $seen = new \SplObjectStorage();
        $queue = [$entry];
        while ([] !== $queue) {
            $block = array_shift($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->children as $op) {
                if ($this->opUsesParent($op)) {
                    throw new CompileFatal(
                        $op->getFile(),
                        max(1, $op->getLine()),
                        self::MESSAGE
                    );
                }
                OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }
    }

    private function opUsesParent(Op $op): bool
    {
        if ($op instanceof Op\Expr\ClassConstFetch) {
            return $this->operandIsParent($op->class);
        }
        if ($op instanceof Op\Expr\StaticPropertyFetch) {
            return $this->operandIsParent($op->class);
        }
        if ($op instanceof Op\Expr\StaticCall) {
            return $this->operandIsParent($op->class);
        }
        if ($op instanceof Op\Expr\New_) {
            return $this->operandIsParent($op->class);
        }

        return false;
    }

    private function operandIsParent(Operand $op): bool
    {
        $name = $this->staticNameFromOperand($op);

        return null !== $name && 'parent' === strtolower($name);
    }

    private function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }
}
