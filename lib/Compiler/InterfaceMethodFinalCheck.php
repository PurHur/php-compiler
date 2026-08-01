<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: interface methods must not be final (#26514).
 *
 * php-src: Zend/zend_compile.c — interface method ZEND_ACC_FINAL forbidden
 */
final class InterfaceMethodFinalCheck
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
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (0 === ($member->func->flags & CfgFunc::FLAG_FINAL)) {
                continue;
            }
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                sprintf(
                    'Interface method %s::%s() must not be final',
                    $ifaceDisplay,
                    $member->func->name
                )
            );
        }
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
