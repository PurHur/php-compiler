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
        if (!self::blockUsesTraitFunctionStaticKeys($method->block, $fromPrefix)) {
            if ($method->getName() === $newName) {
                return $method;
            }

            return self::cloneFuncWithName($method, $newName, $method->block);
        }
        $newBlock = self::cloneBlockRebindingKeys($method->block, $fromPrefix, $className.'::');

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
        if (!self::blockUsesTraitFunctionStaticKeys($block, $fromPrefix)) {
            return $block;
        }

        return self::cloneBlockRebindingKeys($block, $fromPrefix, $className.'::');
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
}
