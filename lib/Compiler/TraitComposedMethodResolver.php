<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Variable;
use PHPCfg\Op\Stmt\ClassMethod;
use PHPCfg\Op\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;

/**
 * Resolve trait-composed methods on a class body (Zend zend_traits.c flattening subset).
 *
 * Used by #[\Override] validation (#6786) and ClassCompileRegistry parent lookup.
 */
final class TraitComposedMethodResolver
{
    /**
     * @return array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}>
     */
    public static function resolve(CfgBlock $classStmts, ClassCompileRegistry $registry): array
    {
        /** @var array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}> */
        $composed = [];
        foreach ($classStmts->children as $child) {
            if (!$child instanceof TraitUse) {
                continue;
            }
            $flattened = self::flattenTraitUse($child, $registry);
            foreach ($flattened['composed'] as $methodLc => $entry) {
                if (!isset($composed[$methodLc])) {
                    $composed[$methodLc] = $entry;
                }
            }
        }

        return $composed;
    }

    /**
     * Trait methods renamed via alias (`f as other`) — original names for #[\Override] (#7384).
     *
     * @return array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}>
     */
    public static function resolveAliasedOriginalMethods(CfgBlock $classStmts, ClassCompileRegistry $registry): array
    {
        /** @var array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}> */
        $origins = [];
        foreach ($classStmts->children as $child) {
            if (!$child instanceof TraitUse) {
                continue;
            }
            $flattened = self::flattenTraitUse($child, $registry);
            foreach ($flattened['aliasedOrigins'] as $methodLc => $entry) {
                if (!isset($origins[$methodLc])) {
                    $origins[$methodLc] = $entry;
                }
            }
        }

        return $origins;
    }

    /**
     * @return array{
     *     composed: array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}>,
     *     aliasedOrigins: array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}>
     * }
     */
    private static function flattenTraitUse(TraitUse $traitUse, ClassCompileRegistry $registry): array
    {
        /** @var array<string, array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}>> */
        $perTraitMethods = [];
        /** @var array<string, string> */
        $usedTraitNameByLc = [];

        foreach ($traitUse->traits as $traitOperand) {
            $traitLc = self::operandLcName($traitOperand);
            if (null === $traitLc) {
                continue;
            }
            $usedTraitNameByLc[$traitLc] = $registry->traitDisplayName($traitLc);
            if (!isset($perTraitMethods[$traitLc])) {
                $perTraitMethods[$traitLc] = self::collectTraitMethods($traitLc, $registry);
            }
        }

        /** @var array<string, true> */
        $excludedByPrecedence = [];
        foreach ($traitUse->adaptations as $adaptation) {
            if (!$adaptation instanceof Precedence) {
                continue;
            }
            $winnerTraitLc = strtolower(ltrim($adaptation->trait->toString(), '\\'));
            $methodLc = strtolower($adaptation->method->name);
            foreach ($adaptation->insteadof as $loserTrait) {
                $loserLc = strtolower(ltrim($loserTrait->toString(), '\\'));
                $excludedByPrecedence["{$loserLc}\0{$methodLc}"] = true;
            }
        }

        /** @var array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}> */
        $merged = [];
        /** @var array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}> */
        $aliasedOrigins = [];
        foreach ($perTraitMethods as $traitLc => $methods) {
            foreach ($methods as $methodLc => $entry) {
                if (isset($excludedByPrecedence["{$traitLc}\0{$methodLc}"])) {
                    continue;
                }
                if (!isset($merged[$methodLc])) {
                    $merged[$methodLc] = $entry;
                }
            }
        }

        foreach ($traitUse->adaptations as $adaptation) {
            if (!$adaptation instanceof Alias) {
                continue;
            }
            $methodLc = strtolower($adaptation->method->name);
            $traitLcFilter = null !== $adaptation->trait
                ? strtolower(ltrim($adaptation->trait->toString(), '\\'))
                : null;
            $source = null;
            if (null !== $traitLcFilter) {
                $source = $perTraitMethods[$traitLcFilter][$methodLc] ?? null;
            } else {
                foreach ($perTraitMethods as $methods) {
                    if (isset($methods[$methodLc])) {
                        $source = $methods[$methodLc];
                        break;
                    }
                }
            }
            if (null === $source) {
                continue;
            }
            $newName = null !== $adaptation->newName
                ? strtolower($adaptation->newName->name)
                : $methodLc;
            if (null === $traitLcFilter) {
                unset($merged[$methodLc]);
            }
            $merged[$newName] = $source;
            if ($newName !== $methodLc) {
                $aliasedOrigins[$methodLc] = $source;
            }
        }

        return [
            'composed' => $merged,
            'aliasedOrigins' => $aliasedOrigins,
        ];
    }

    /**
     * @return array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}>
     */
    private static function collectTraitMethods(string $traitLc, ClassCompileRegistry $registry): array
    {
        /** @var array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}> */
        $methods = [];
        $visited = [];
        self::collectTraitMethodsRecursive($traitLc, $registry, $methods, $visited);

        return $methods;
    }

    /**
     * @param array<string, array{sig: MethodSig, ownerLc: string, ownerDisplay: string}> $methods
     * @param array<string, true>                                                          $visited
     */
    private static function collectTraitMethodsRecursive(
        string $traitLc,
        ClassCompileRegistry $registry,
        array &$methods,
        array &$visited
    ): void {
        if (isset($visited[$traitLc])) {
            return;
        }
        $visited[$traitLc] = true;
        $traitStmts = $registry->getTraitStmts($traitLc);
        if (null === $traitStmts) {
            return;
        }
        $ownerDisplay = $registry->traitDisplayName($traitLc);
        foreach ($traitStmts->children as $child) {
            if ($child instanceof TraitUse) {
                foreach ($child->traits as $traitOperand) {
                    $nestedLc = self::operandLcName($traitOperand);
                    if (null !== $nestedLc) {
                        self::collectTraitMethodsRecursive($nestedLc, $registry, $methods, $visited);
                    }
                }
                foreach (self::flattenTraitUse($child, $registry)['composed'] as $methodLc => $entry) {
                    if (!isset($methods[$methodLc])) {
                        $methods[$methodLc] = $entry;
                    }
                }
                continue;
            }
            if (!$child instanceof ClassMethod) {
                continue;
            }
            $methodLc = strtolower($child->func->name);
            if (!isset($methods[$methodLc])) {
                $methods[$methodLc] = [
                    'sig' => MethodSig::fromFunc($child->func, $traitLc),
                    'ownerLc' => $traitLc,
                    'ownerDisplay' => $ownerDisplay,
                ];
            }
        }
    }

    private static function operandLcName(Operand $op): ?string
    {
        if ($op instanceof Literal && is_string($op->value)) {
            return strtolower(ltrim($op->value, '\\'));
        }
        if ($op instanceof Variable) {
            return self::operandLcName($op->name);
        }

        return null;
    }
}
