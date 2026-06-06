<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: interface constants must be public (#6868).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_decl() interface visibility check
 */
final class InterfaceConstVisibilityCheck
{
    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Interface_) {
                $check->validateInterface($child);
            }
        }
    }

    private function validateInterface(Op\Stmt\Interface_ $iface): void
    {
        $ifaceDisplay = $this->operandDisplayName($iface->name, 'interface');
        foreach ($iface->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if ($this->isPublicConst($member)) {
                continue;
            }
            $constName = $this->staticNameFromOperand($member->name) ?? 'const';
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                sprintf(
                    'Access type for interface constant %s::%s must be public',
                    $ifaceDisplay,
                    $constName
                )
            );
        }
    }

    private function isPublicConst(Op\Terminal\Const_ $const): bool
    {
        if (!property_exists($const, 'flags')) {
            return true;
        }
        $flags = (int) $const->flags;

        return 0 === ($flags & (CfgFunc::FLAG_PRIVATE | CfgFunc::FLAG_PROTECTED));
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
