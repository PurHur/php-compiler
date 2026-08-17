<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * Per-class function-static keys for trait-composed methods (#6660, zend_traits_copy_statics).
 *
 * Always rebinds the composed Func name to {@code $className::$methodName} (zend_traits.c
 * copies scope to the using class). Without that, #[\Deprecated] use-site notices keep the
 * trait name ({@code Tr::m}) instead of Zend's using-class form ({@code C::m}) (#29392).
 *
 * Also early-binds lexical {@code self} in param/return typehints to the using class
 * (zend_inheritance.c trait method copy, #31744). Trait compile keeps the keyword so the
 * original method body can be cloned per composing class.
 */
final class TraitMethodFunctionStatic
{
    public static function bindMethod(Func $method, string $className, string $traitName, string $methodName): Func
    {
        if (!$method instanceof Func\PHP) {
            return $method;
        }
        $fromPrefix = $traitName.'::';
        $newName = $className.'::'.$methodName;
        $needsStatic = self::blockUsesTraitFunctionStaticKeys($method->block, $fromPrefix);
        $needsSelf = self::blockHasSelfTypeHints($method->block);
        if (!$needsStatic && !$needsSelf) {
            if ($method->getName() === $newName) {
                return $method;
            }

            return self::cloneFuncWithName($method, $newName, $method->block);
        }
        // Clone when rebinding function-static keys and/or trait `self` types (#31744).
        // Same from/to prefix is a graph clone with no key rewrite.
        $newBlock = self::cloneBlockRebindingKeys(
            $method->block,
            $fromPrefix,
            $needsStatic ? $className.'::' : $fromPrefix
        );
        if ($needsSelf) {
            self::rewriteSelfTypeHintsInMethod($newBlock, $className);
        }

        return self::cloneFuncWithName($method, $newName, $newBlock);
    }

    private static function cloneFuncWithName(Func\PHP $method, string $newName, Block $block): Func\PHP
    {
        $bound = new Func\PHP($newName, $block);
        $bound->deprecated = $method->deprecated;
        $bound->sourceLocation = $method->sourceLocation;
        $bound->parameterMetadata = $method->parameterMetadata;
        $bound->attributeNames = $method->attributeNames;
        $bound->attributeEntries = $method->attributeEntries;

        return $bound;
    }

    public static function bindBlock(Block $block, string $className, string $traitName): Block
    {
        $fromPrefix = $traitName.'::';
        $needsStatic = self::blockUsesTraitFunctionStaticKeys($block, $fromPrefix);
        $needsSelf = self::blockHasSelfTypeHints($block);
        if (!$needsStatic && !$needsSelf) {
            return $block;
        }
        $clone = self::cloneBlockRebindingKeys(
            $block,
            $fromPrefix,
            $needsStatic ? $className.'::' : $fromPrefix
        );
        if ($needsSelf) {
            self::rewriteSelfTypeHintsInMethod($clone, $className);
        }

        return $clone;
    }

    private static function blockUsesTraitFunctionStaticKeys(Block $block, string $fromPrefix): bool
    {
        foreach (self::collectMethodBlocks($block) as $methodBlock) {
            foreach ($methodBlock->opCodes as $op) {
                if (!self::isFunctionStaticStorageOpcode($op->type) || !isset($methodBlock->constants[$op->arg2])) {
                    continue;
                }
                if (str_starts_with($methodBlock->constants[$op->arg2]->toString(), $fromPrefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<Block> */
    private static function collectMethodBlocks(Block $block): array
    {
        $seen = new \SplObjectStorage();
        $out = [];
        self::collectMethodBlocksInternal($block, $seen, $out);

        return $out;
    }

    /** @param list<Block> $out */
    private static function collectMethodBlocksInternal(Block $block, \SplObjectStorage $seen, array &$out): void
    {
        if ($seen->contains($block)) {
            return;
        }
        $seen->attach($block);
        $out[] = $block;
        foreach ($block->blocks as $nested) {
            self::collectMethodBlocksInternal($nested, $seen, $out);
        }
        foreach ($block->opCodes as $op) {
            foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                if ($sub instanceof Block) {
                    self::collectMethodBlocksInternal($sub, $seen, $out);
                }
            }
        }
    }

    private static function isFunctionStaticStorageOpcode(int $type): bool
    {
        return OpCode::TYPE_DECLARE_FUNCTION_STATIC === $type
            || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED === $type
            || OpCode::TYPE_FUNCTION_STATIC_INIT_STORE === $type;
    }

    private static function cloneBlockRebindingKeys(
        Block $block,
        string $fromPrefix,
        string $toPrefix
    ): Block {
        $map = new \SplObjectStorage();

        return self::cloneBlockRebindingKeysInternal($block, $map, $fromPrefix, $toPrefix);
    }

    private static function cloneBlockRebindingKeysInternal(
        Block $block,
        \SplObjectStorage $map,
        string $fromPrefix,
        string $toPrefix
    ): Block {
        if ($map->contains($block)) {
            return $map[$block];
        }
        $clone = clone $block;
        $map[$block] = $clone;
        $clone->constants = [];
        foreach ($block->constants as $idx => $const) {
            $newConst = new Variable();
            $newConst->copyFrom($const);
            if (Variable::TYPE_STRING === $newConst->type) {
                $str = $newConst->toString();
                if (str_starts_with($str, $fromPrefix)) {
                    $patched = new Variable(Variable::TYPE_STRING);
                    $patched->string($toPrefix.substr($str, strlen($fromPrefix)), $newConst->stringInterned);
                    $newConst = $patched;
                }
            }
            $clone->constants[$idx] = $newConst;
        }
        $clone->blocks = [];
        foreach ($block->blocks as $idx => $nested) {
            $clone->blocks[$idx] = self::cloneBlockRebindingKeysInternal($nested, $map, $fromPrefix, $toPrefix);
        }
        $clone->opCodes = [];
        foreach ($block->opCodes as $op) {
            $opClone = clone $op;
            if ($opClone->block1 instanceof Block) {
                $opClone->block1 = self::cloneBlockRebindingKeysInternal($opClone->block1, $map, $fromPrefix, $toPrefix);
            }
            if ($opClone->block2 instanceof Block) {
                $opClone->block2 = self::cloneBlockRebindingKeysInternal($opClone->block2, $map, $fromPrefix, $toPrefix);
            }
            if ($opClone->block3 instanceof Block) {
                $opClone->block3 = self::cloneBlockRebindingKeysInternal($opClone->block3, $map, $fromPrefix, $toPrefix);
            }
            $clone->opCodes[] = $opClone;
        }
        $clone->nOpCodes = count($clone->opCodes);

        return $clone;
    }

    /**
     * True when any block in the method has a lexical {@code self} class typehint (#31744).
     */
    private static function blockHasSelfTypeHints(Block $block): bool
    {
        foreach (self::collectMethodBlocks($block) as $methodBlock) {
            if (self::blockContainsSelfTypeHint($methodBlock)) {
                return true;
            }
        }

        return false;
    }

    private static function blockContainsSelfTypeHint(Block $block): bool
    {
        foreach ($block->paramClassConstraints as $name) {
            if (self::isSelfToken($name)) {
                return true;
            }
        }
        foreach ($block->paramDeclaredTypeLabels as $label) {
            if (self::labelContainsSelfToken($label)) {
                return true;
            }
        }
        foreach ($block->paramIntersectionConstraints as $names) {
            foreach ($names as $name) {
                if (self::isSelfToken($name)) {
                    return true;
                }
            }
        }
        foreach ($block->paramIntersectionDisplayLabels as $label) {
            if (self::labelContainsSelfToken($label)) {
                return true;
            }
        }
        foreach ($block->paramDnfConstraints as $arms) {
            if (self::dnfArmsContainSelf($arms)) {
                return true;
            }
        }
        foreach ($block->paramVariadicElementIntersectionConstraints as $names) {
            foreach ($names as $name) {
                if (self::isSelfToken($name)) {
                    return true;
                }
            }
        }
        foreach ($block->paramVariadicElementIntersectionDisplayLabels as $label) {
            if (self::labelContainsSelfToken($label)) {
                return true;
            }
        }
        foreach ($block->paramVariadicElementDnfConstraints as $arms) {
            if (self::dnfArmsContainSelf($arms)) {
                return true;
            }
        }
        if (null !== $block->returnClassConstraint && self::isSelfToken($block->returnClassConstraint)) {
            return true;
        }
        if (null !== $block->returnDeclaredTypeLabel && self::labelContainsSelfToken($block->returnDeclaredTypeLabel)) {
            return true;
        }
        if (null !== $block->returnDnfConstraints && self::dnfArmsContainSelf($block->returnDnfConstraints)) {
            return true;
        }

        return false;
    }

    /**
     * zend_inheritance.c: {@code self} in copied trait method types becomes the using class.
     */
    private static function rewriteSelfTypeHintsInMethod(Block $block, string $className): void
    {
        $display = ltrim($className, '\\');
        foreach (self::collectMethodBlocks($block) as $methodBlock) {
            self::rewriteSelfTypeHintsOnBlock($methodBlock, $display);
        }
    }

    private static function rewriteSelfTypeHintsOnBlock(Block $block, string $classDisplay): void
    {
        foreach ($block->paramClassConstraints as $slot => $name) {
            $block->paramClassConstraints[$slot] = self::rewriteSelfName($name, $classDisplay);
        }
        foreach ($block->paramDeclaredTypeLabels as $slot => $label) {
            $block->paramDeclaredTypeLabels[$slot] = self::rewriteSelfInLabel($label, $classDisplay);
        }
        foreach ($block->paramIntersectionConstraints as $slot => $names) {
            $block->paramIntersectionConstraints[$slot] = self::rewriteSelfNameList($names, $classDisplay);
        }
        foreach ($block->paramIntersectionDisplayLabels as $slot => $label) {
            $block->paramIntersectionDisplayLabels[$slot] = self::rewriteSelfInLabel($label, $classDisplay);
        }
        foreach ($block->paramDnfConstraints as $slot => $arms) {
            $block->paramDnfConstraints[$slot] = self::rewriteSelfInDnfArms($arms, $classDisplay);
        }
        foreach ($block->paramVariadicElementIntersectionConstraints as $slot => $names) {
            $block->paramVariadicElementIntersectionConstraints[$slot] = self::rewriteSelfNameList($names, $classDisplay);
        }
        foreach ($block->paramVariadicElementIntersectionDisplayLabels as $slot => $label) {
            $block->paramVariadicElementIntersectionDisplayLabels[$slot] = self::rewriteSelfInLabel($label, $classDisplay);
        }
        foreach ($block->paramVariadicElementDnfConstraints as $slot => $arms) {
            $block->paramVariadicElementDnfConstraints[$slot] = self::rewriteSelfInDnfArms($arms, $classDisplay);
        }
        if (null !== $block->returnClassConstraint) {
            $block->returnClassConstraint = self::rewriteSelfName($block->returnClassConstraint, $classDisplay);
        }
        if (null !== $block->returnDeclaredTypeLabel) {
            $block->returnDeclaredTypeLabel = self::rewriteSelfInLabel($block->returnDeclaredTypeLabel, $classDisplay);
        }
        if (null !== $block->returnDnfConstraints) {
            $block->returnDnfConstraints = self::rewriteSelfInDnfArms($block->returnDnfConstraints, $classDisplay);
        }
    }

    private static function isSelfToken(string $name): bool
    {
        return 'self' === strtolower(ltrim($name, '\\'));
    }

    private static function labelContainsSelfToken(string $label): bool
    {
        return 1 === preg_match('/(?<![A-Za-z0-9_\\\\])self(?![A-Za-z0-9_])/i', $label);
    }

    private static function rewriteSelfName(string $name, string $classDisplay): string
    {
        return self::isSelfToken($name) ? $classDisplay : $name;
    }

    /** @param list<string> $names @return list<string> */
    private static function rewriteSelfNameList(array $names, string $classDisplay): array
    {
        $classLc = strtolower($classDisplay);
        $out = [];
        foreach ($names as $name) {
            $out[] = self::isSelfToken($name) ? $classLc : $name;
        }

        return $out;
    }

    private static function rewriteSelfInLabel(string $label, string $classDisplay): string
    {
        $rewritten = preg_replace(
            '/(?<![A-Za-z0-9_\\\\])self(?![A-Za-z0-9_])/i',
            $classDisplay,
            $label
        );

        return is_string($rewritten) ? $rewritten : $label;
    }

    /**
     * @param list<array<string, mixed>> $arms
     */
    private static function dnfArmsContainSelf(array $arms): bool
    {
        foreach ($arms as $arm) {
            $kind = $arm['kind'] ?? '';
            if ('literal' === $kind) {
                if (isset($arm['name']) && self::isSelfToken((string) $arm['name'])) {
                    return true;
                }
                if (isset($arm['display']) && self::labelContainsSelfToken((string) $arm['display'])) {
                    return true;
                }
            } elseif ('intersection' === $kind) {
                foreach ($arm['interfaces'] ?? [] as $iface) {
                    if (self::isSelfToken((string) $iface)) {
                        return true;
                    }
                }
                if (isset($arm['display']) && self::labelContainsSelfToken((string) $arm['display'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $arms
     * @return list<array<string, mixed>>
     */
    private static function rewriteSelfInDnfArms(array $arms, string $classDisplay): array
    {
        $classLc = strtolower($classDisplay);
        $out = [];
        foreach ($arms as $arm) {
            $kind = $arm['kind'] ?? '';
            if ('literal' === $kind) {
                if (isset($arm['name']) && self::isSelfToken((string) $arm['name'])) {
                    $arm['name'] = $classLc;
                }
                if (isset($arm['display'])) {
                    $arm['display'] = self::rewriteSelfInLabel((string) $arm['display'], $classDisplay);
                }
            } elseif ('intersection' === $kind) {
                if (isset($arm['interfaces']) && is_array($arm['interfaces'])) {
                    $arm['interfaces'] = self::rewriteSelfNameList($arm['interfaces'], $classDisplay);
                }
                if (isset($arm['display'])) {
                    $arm['display'] = self::rewriteSelfInLabel((string) $arm['display'], $classDisplay);
                }
            }
            $out[] = $arm;
        }

        return $out;
    }
}
