<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_ as ClassNode;

/**
 * Compile-time check: cannot override parent/interface final class constants (#4455).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_decl;
 * Zend/zend_inheritance.c — constant override checks
 */
final class FinalClassConstCheck
{
    /**
     * @var array<string, array{display: string, constants: array<string, array{final: bool, display: string}>, extends: ?string, implements: list<string>, ifaceExtends: list<string>}>
     */
    private array $types = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
    }

    private function collect(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                $this->collectClass($child);
            } elseif ($child instanceof Op\Stmt\Interface_) {
                $this->collectInterface($child);
            } elseif ($child instanceof Op\Stmt\Trait_) {
                $this->collectTrait($child);
            } elseif ($child instanceof Op\Stmt\Enum_) {
                $this->collectEnum($child);
            }
        }
    }

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $implements = [];
        foreach ($class->implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null !== $ifaceLc) {
                $implements[] = $ifaceLc;
            }
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'constants' => $this->collectConstants($class->stmts->children),
            'extends' => $parentLc,
            'implements' => $implements,
            'ifaceExtends' => [],
        ];
    }

    private function collectInterface(Op\Stmt\Interface_ $iface): void
    {
        $lc = $this->operandLcName($iface->name);
        if (null === $lc) {
            return;
        }
        $extends = [];
        foreach ($iface->extends as $parentOperand) {
            $parentLc = $this->operandLcName($parentOperand);
            if (null !== $parentLc) {
                $extends[] = $parentLc;
            }
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($iface->name, $lc),
            'constants' => $this->collectConstants($iface->stmts->children),
            'extends' => null,
            'implements' => [],
            'ifaceExtends' => $extends,
        ];
    }

    private function collectTrait(Op\Stmt\Trait_ $trait): void
    {
        $lc = $this->operandLcName($trait->name);
        if (null === $lc) {
            return;
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($trait->name, $lc),
            'constants' => $this->collectConstants($trait->stmts->children),
            'extends' => null,
            'implements' => [],
            'ifaceExtends' => [],
        ];
    }

    private function collectEnum(Op\Stmt\Enum_ $enum): void
    {
        $lc = $this->operandLcName($enum->name);
        if (null === $lc) {
            return;
        }
        $implements = [];
        foreach ($enum->implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null !== $ifaceLc) {
                $implements[] = $ifaceLc;
            }
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($enum->name, $lc),
            'constants' => $this->collectConstants($enum->stmts->children),
            'extends' => null,
            'implements' => $implements,
            'ifaceExtends' => [],
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, array{final: bool, display: string}>
     */
    private function collectConstants(array $members): array
    {
        $constants = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            $name = $this->staticNameFromOperand($member->name);
            if (null === $name) {
                continue;
            }
            $constants[strtolower($name)] = [
                'final' => $this->isFinalConst($member),
                'display' => $name,
            ];
        }

        return $constants;
    }

    private function isFinalConst(Op\Terminal\Const_ $const): bool
    {
        if (!property_exists($const, 'flags')) {
            return false;
        }

        return 0 !== ($const->flags & ClassNode::MODIFIER_FINAL);
    }

    private function verify(): void
    {
        foreach ($this->types as $lc => $type) {
            $inheritedFinals = $this->collectClassChainFinalConsts($type['extends']);
            foreach ($type['implements'] as $ifaceLc) {
                $inheritedFinals = array_merge(
                    $inheritedFinals,
                    $this->collectInterfaceChainFinalConsts($ifaceLc)
                );
            }
            foreach ($type['ifaceExtends'] as $parentIfaceLc) {
                $inheritedFinals = array_merge(
                    $inheritedFinals,
                    $this->collectInterfaceChainFinalConsts($parentIfaceLc)
                );
            }
            foreach ($type['constants'] as $constLc => $constInfo) {
                if (!isset($inheritedFinals[$constLc])) {
                    continue;
                }
                throw new \CompileError(sprintf(
                    '%s::%s cannot override final constant %s::%s',
                    $type['display'],
                    $constInfo['display'],
                    $inheritedFinals[$constLc]['ownerDisplay'],
                    $inheritedFinals[$constLc]['constDisplay']
                ));
            }
        }
    }

    /**
     * @return array<string, array{ownerDisplay: string, constDisplay: string}>
     */
    private function collectClassChainFinalConsts(?string $classLc): array
    {
        $finals = [];
        $visited = [];
        while (null !== $classLc && '' !== $classLc && !isset($visited[$classLc])) {
            $visited[$classLc] = true;
            if (!isset($this->types[$classLc])) {
                break;
            }
            $type = $this->types[$classLc];
            foreach ($type['constants'] as $nameLc => $constInfo) {
                if (!$constInfo['final'] || isset($finals[$nameLc])) {
                    continue;
                }
                $finals[$nameLc] = [
                    'ownerDisplay' => $type['display'],
                    'constDisplay' => $constInfo['display'],
                ];
            }
            $classLc = $type['extends'];
        }

        return $finals;
    }

    /**
     * @return array<string, array{ownerDisplay: string, constDisplay: string}>
     */
    private function collectInterfaceChainFinalConsts(string $ifaceLc): array
    {
        $finals = [];
        $visited = [];
        $queue = [$ifaceLc];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ('' === $current || isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (!isset($this->types[$current])) {
                continue;
            }
            $type = $this->types[$current];
            foreach ($type['constants'] as $nameLc => $constInfo) {
                if (!$constInfo['final'] || isset($finals[$nameLc])) {
                    continue;
                }
                $finals[$nameLc] = [
                    'ownerDisplay' => $type['display'],
                    'constDisplay' => $constInfo['display'],
                ];
            }
            foreach ($type['ifaceExtends'] as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return $finals;
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
