<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: child classes cannot override parent final methods (#4263).
 *
 * php-src: Zend/zend_compile.c — zend_check_inheritance;
 * Zend/zend_inheritance.c — zend_inheritance_check_ex()
 */
final class FinalMethodOverrideCheck
{
    /** @var array<string, array{display: string, extends: ?string, implements: list<string>, stmts: CfgBlock}> */
    private array $classes = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $registry = $check->buildRegistry($script);
        $check->verify($registry);
    }

    private function collect(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $lc = $this->operandLcName($child->name);
            if (null === $lc) {
                continue;
            }
            $parentLc = null;
            if (null !== $child->extends) {
                $parentLc = $this->operandLcName($child->extends);
            }
            $interfaceLcs = [];
            foreach ($child->implements as $ifaceOperand) {
                $ifaceLc = $this->operandLcName($ifaceOperand);
                if (null !== $ifaceLc) {
                    $interfaceLcs[] = $ifaceLc;
                }
            }
            $this->classes[$lc] = [
                'display' => $this->operandDisplayName($child->name, $lc),
                'extends' => $parentLc,
                'implements' => $interfaceLcs,
                'stmts' => $child->stmts,
            ];
        }
    }

    private function buildRegistry(Script $script): ClassCompileRegistry
    {
        $registry = new ClassCompileRegistry();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Trait_) {
                $name = $this->staticNameFromOperand($child->name);
                if (null !== $name) {
                    $registry->registerTrait($name, $child->stmts);
                }
                continue;
            }
            if ($child instanceof Op\Stmt\Interface_) {
                $name = $this->staticNameFromOperand($child->name);
                if (null === $name) {
                    continue;
                }
                $extendsLcs = [];
                foreach ($child->extends as $parentIface) {
                    $parentLc = $this->operandLcName($parentIface);
                    if (null !== $parentLc) {
                        $extendsLcs[] = $parentLc;
                    }
                }
                $registry->registerInterface($name, $extendsLcs, $child->stmts);
            }
        }
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $name = $this->staticNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            $parentLc = null;
            if (null !== $child->extends) {
                $parentLc = $this->operandLcName($child->extends);
            }
            $interfaceLcs = [];
            foreach ($child->implements as $ifaceOperand) {
                $ifaceLc = $this->operandLcName($ifaceOperand);
                if (null !== $ifaceLc) {
                    $interfaceLcs[] = $ifaceLc;
                }
            }
            $registry->registerClass($name, $parentLc, $interfaceLcs, $child->stmts);
        }

        return $registry;
    }

    private function verify(ClassCompileRegistry $registry): void
    {
        foreach ($this->classes as $classLc => $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || '' === $parentLc) {
                continue;
            }
            foreach ($this->introducedMethods($class['stmts'], $registry) as $methodLc => $methodDisplay) {
                $parent = $registry->findOverriddenMethod(
                    $parentLc,
                    $class['implements'],
                    $methodLc,
                    $classLc
                );
                if (null === $parent || !$parent['sig']->isFinal) {
                    continue;
                }
                throw new \CompileError(sprintf(
                    'Cannot override final method %s::%s()',
                    $parent['ownerDisplay'],
                    $methodDisplay
                ));
            }
        }
    }

    /**
     * @return array<string, string> method lc => display name
     */
    private function introducedMethods(CfgBlock $stmts, ClassCompileRegistry $registry): array
    {
        $methods = [];
        foreach ($stmts->children as $child) {
            if (!$child instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodLc = strtolower($child->func->name);
            $methods[$methodLc] = $child->func->name;
        }
        foreach (TraitComposedMethodResolver::resolve($stmts, $registry) as $methodLc => $entry) {
            if (!isset($methods[$methodLc])) {
                $methods[$methodLc] = $methodLc;
            }
        }

        return $methods;
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallbackLc): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallbackLc;
        }
        $short = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));
            $short = end($parts) ?: $name;
        }

        return $short;
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
