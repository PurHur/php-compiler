<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: duplicate class/interface/trait/enum user constants (#5219, zend_compile.c).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_decl
 */
final class ClassConstDuplicateCheck
{
    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_
                || $child instanceof Op\Stmt\Interface_
                || $child instanceof Op\Stmt\Trait_
                || $child instanceof Op\Stmt\Enum_) {
                $check->validateType($child);
            }
        }
    }

    private function validateType(Op\Stmt\Class_|Op\Stmt\Interface_|Op\Stmt\Trait_|Op\Stmt\Enum_ $type): void
    {
        $typeDisplay = $this->operandDisplayName($type->name, 'class');
        /** @var array<string, true> */
        $seen = [];
        foreach ($type->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if ($type instanceof Op\Stmt\Enum_ && $this->memberIsEnumCase($member, $type)) {
                continue;
            }
            $constName = $this->operandDisplayName($member->name, 'const');
            $lc = strtolower($constName);
            if (isset($seen[$lc])) {
                throw new CompileFatal(
                    $member->getFile(),
                    $member->getLine(),
                    sprintf('Cannot redefine class constant %s::%s', $typeDisplay, $constName)
                );
            }
            $seen[$lc] = true;
        }
    }

    private function memberIsEnumCase(Op\Terminal\Const_ $member, Op\Stmt\Enum_ $enum): bool
    {
        if (property_exists($member, 'isEnumCase')) {
            return $member->isEnumCase;
        }
        if (property_exists($member, 'declaredType') && null !== $member->declaredType) {
            return false;
        }
        $flags = property_exists($member, 'flags') ? (int) $member->flags : 0;
        if (0 !== ($flags & (\PHPCfg\Func::FLAG_PROTECTED | \PHPCfg\Func::FLAG_PRIVATE | \PHPCfg\Func::FLAG_FINAL))) {
            return false;
        }
        if (null === $enum->backedType || !$enum->backedType instanceof Op\Type\Literal) {
            return 0 === $flags;
        }
        $backedType = $enum->backedType->name;
        if ('int' !== $backedType && 'string' !== $backedType) {
            return false;
        }

        return true;
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
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
