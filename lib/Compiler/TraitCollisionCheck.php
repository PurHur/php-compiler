<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\VM\TraitCompositionConflictMessage;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: horizontal trait method collisions without insteadof (#3416).
 *
 * php-src: Zend/zend_traits.c — zend_traits_get_method, horizontal conflict detection
 */
final class TraitCollisionCheck
{
    /** @var array<string, array{display: string, methods: array<string, true>, promotedProperties: array<string, true>, instanceProperties: array<string, true>, staticProperties: array<string, true>}> */
    private array $traits = [];

    /** @var array<string, array{display: string, extends: ?string, ownMethods: array<string, true>, ownProperties: array<string, true>, ownStaticProperties: array<string, true>, traitUses: list<list<string>>}> */
    private array $classes = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
    }

    private function collect(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Trait_) {
                $this->collectTrait($child);
            } elseif ($child instanceof Op\Stmt\Class_) {
                $this->collectClass($child);
            }
        }
    }

    private function collectTrait(Op\Stmt\Trait_ $trait): void
    {
        $lc = $this->operandLcName($trait->name);
        if (null === $lc) {
            return;
        }
        $this->traits[$lc] = [
            'display' => $this->operandDisplayName($trait->name, $lc),
            'methods' => $this->collectConcreteMethods($trait->stmts->children),
            'promotedProperties' => $this->collectPromotedPropertyNames($trait->stmts->children),
            'instanceProperties' => $this->collectInstancePropertyNames($trait->stmts->children),
            'staticProperties' => $this->collectStaticPropertyNames($trait->stmts->children),
        ];
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
        $traitUses = [];
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\TraitUse) {
                if ([] !== $member->adaptations) {
                    continue;
                }
                $traits = [];
                $seenInUse = [];
                foreach ($member->traits as $traitOperand) {
                    $traitLc = $this->operandLcName($traitOperand);
                    if (null === $traitLc || isset($seenInUse[$traitLc])) {
                        continue;
                    }
                    $seenInUse[$traitLc] = true;
                    $traits[] = $traitLc;
                }
                if ([] !== $traits) {
                    $traitUses[] = $traits;
                }
            }
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'extends' => $parentLc,
            'ownMethods' => $this->collectConcreteMethods($class->stmts->children),
            'ownProperties' => $this->collectInstancePropertyNames($class->stmts->children),
            'ownStaticProperties' => $this->collectStaticPropertyNames($class->stmts->children),
            'traitUses' => $traitUses,
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectConcreteMethods(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if ($this->methodHasBody($member)) {
                $methods[strtolower($member->func->name)] = true;
            }
        }

        return $methods;
    }

    private function methodHasBody(Op\Stmt\ClassMethod $method): bool
    {
        $cfg = $method->func->cfg;
        if (null === $cfg) {
            return false;
        }

        return [] !== $cfg->children;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectPromotedPropertyNames(array $members): array
    {
        $names = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod || '__construct' !== $member->func->name) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!$this->isPromotedParam($param)) {
                    continue;
                }
                if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
                    $names[strtolower($param->name->value)] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectInstancePropertyNames(array $members): array
    {
        $names = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\Property || $member->static) {
                continue;
            }
            if ($member->name instanceof Operand\Literal && is_string($member->name->value)) {
                $names[strtolower($member->name->value)] = true;
            }
        }

        return $names;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectStaticPropertyNames(array $members): array
    {
        $names = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\Property || !$member->static) {
                continue;
            }
            if ($member->name instanceof Operand\Literal && is_string($member->name->value)) {
                $names[strtolower($member->name->value)] = true;
            }
        }

        return $names;
    }

    private function isPromotedParam(Op\Expr\Param $param): bool
    {
        return property_exists($param, 'promotionFlags') && 0 !== $param->promotionFlags;
    }

    private function verify(): void
    {
        foreach ($this->classes as $class) {
            $this->verifyClass($class);
        }
    }

    /**
     * @param array{display: string, extends: ?string, ownMethods: array<string, true>, ownProperties: array<string, true>, ownStaticProperties: array<string, true>, traitUses: list<list<string>>} $class
     */
    private function verifyClass(array $class): void
    {
        $excluded = $class['ownMethods'];
        $current = $class['extends'];
        $visited = [];
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            foreach ($this->classes[$current]['ownMethods'] as $name => $_) {
                $excluded[$name] = true;
            }
            $current = $this->classes[$current]['extends'];
        }

        /** @var array<string, string> method lc => trait display */
        $traitSources = [];
        /** @var array<string, string> property lc => trait display */
        $traitPropertySources = [];
        /** @var array<string, string> static property lc => trait display */
        $traitStaticPropertySources = [];
        /** @var array<string, true> trait lc => already applied (php-src dedupes duplicate use entries) */
        $appliedTraits = [];
        foreach ($class['traitUses'] as $useGroup) {
            foreach ($useGroup as $traitLc) {
                if (isset($appliedTraits[$traitLc])) {
                    continue;
                }
                if (!isset($this->traits[$traitLc])) {
                    continue;
                }
                $appliedTraits[$traitLc] = true;
                $trait = $this->traits[$traitLc];
                foreach ($trait['methods'] as $methodLc => $_) {
                    if (isset($excluded[$methodLc])) {
                        continue;
                    }
                    if (isset($traitSources[$methodLc])) {
                        throw new \CompileError(
                            "Trait method {$trait['display']}::{$methodLc} has not been applied as "
                            ."{$class['display']}::{$methodLc}, because of collision with "
                            ."{$traitSources[$methodLc]}::{$methodLc}"
                        );
                    }
                    $traitSources[$methodLc] = $trait['display'];
                }
                foreach ($trait['promotedProperties'] as $propLc => $_) {
                    if (isset($class['ownProperties'][$propLc])) {
                        throw new \CompileError(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                            $class['display'],
                            $trait['display'],
                            $propLc
                        ));
                    }
                    if (isset($traitPropertySources[$propLc])) {
                        throw new \CompileError(TraitCompositionConflictMessage::incompatibleProperty(
                            $traitPropertySources[$propLc],
                            $trait['display'],
                            $propLc,
                            $class['display']
                        ));
                    }
                    $traitPropertySources[$propLc] = $trait['display'];
                }
                foreach ($trait['instanceProperties'] as $propLc => $_) {
                    if (isset($class['ownProperties'][$propLc])) {
                        throw new \CompileError(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                            $class['display'],
                            $trait['display'],
                            $propLc
                        ));
                    }
                    if (isset($traitPropertySources[$propLc])) {
                        throw new \CompileError(TraitCompositionConflictMessage::incompatibleProperty(
                            $traitPropertySources[$propLc],
                            $trait['display'],
                            $propLc,
                            $class['display']
                        ));
                    }
                    $traitPropertySources[$propLc] = $trait['display'];
                }
                foreach ($trait['staticProperties'] as $propLc => $_) {
                    if (isset($class['ownStaticProperties'][$propLc])) {
                        throw new \CompileError(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                            $class['display'],
                            $trait['display'],
                            $propLc
                        ));
                    }
                    if (isset($traitStaticPropertySources[$propLc])) {
                        throw new \CompileError(TraitCompositionConflictMessage::incompatibleProperty(
                            $traitStaticPropertySources[$propLc],
                            $trait['display'],
                            $propLc,
                            $class['display']
                        ));
                    }
                    $traitStaticPropertySources[$propLc] = $trait['display'];
                }
            }
        }
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
