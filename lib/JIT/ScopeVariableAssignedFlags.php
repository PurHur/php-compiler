<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * Per-scope-variable assigned flags for JIT undefined-variable guards (#10360).
 *
 * User-function CVs use per-activation entry allocas (Zend initializedSlots). {main}
 * script CVs still use a module global — marks and guards can lower in __init__ vs @main
 * (#36081) and must share one slot across those LLVM functions (#36190).
 */
final class ScopeVariableAssignedFlags
{
    /** @var array<string, Value> module-global i8 per {main} scope key */
    private static array $mainFlags = [];

    /** @var array<string, Value> entry-block i8 alloca per owning LLVM function + user scope key */
    private static array $flags = [];

    /**
     * Scope keys marked assigned while the insert block was still the function entry
     * (unconditional prologue). Entry dominates every later read (#36386).
     *
     * @var array<string, true> moduleId\0ownerOrMain\0key
     */
    private static array $definitelyAssigned = [];

    /**
     * Names assigned inside a CFG {@see Block} (spl_object_id → name → true).
     * Used with {@see Block::$parents} dataflow so mid-function for-init that
     * dominates a later loop header elides undef guards (#36386).
     *
     * @var array<int, array<string, true>>
     */
    private static array $cfgBlockAssigned = [];

    public static function flagKey(Context $context, string $name): string
    {
        $resolved = $context->resolveRefAliasName($name);
        $block = $context->jitEnclosingBlock ?? $context->jitFunctionRootBlock;
        if (null !== $block && $block->isMainScript()) {
            // {main} CV flags must not key off activeFunction — nested class/method
            // lowering can leave it stale while still emitting main-body guards (#31835 / #36081).
            return '{main}'."\0".$resolved;
        }

        // User-function CV flags are scoped by the owning LLVM function in ensureFlag()
        // (spl_object_id(parentFunction)); do not prefix activeFunction — it can disagree
        // with jitEnclosingBlock during call lowering (#36405).
        return $resolved;
    }

    public static function ensureFlag(Context $context, string $key): Value
    {
        if (str_starts_with($key, '{main}'."\0")) {
            return self::ensureMainModuleFlag($context, $key);
        }

        $owner = self::ownerFunctionForScopeFlags($context);
        $cacheKey = spl_object_id($owner)."\0".$key;
        if (!isset(self::$flags[$cacheKey])) {
            $i8 = $context->getTypeFromString('int8');
            $flag = BasicBlockHelper::entryAllocaForFunction($context, $owner, $i8);
            BasicBlockHelper::storeAtFunctionEntry(
                $context,
                $owner,
                $i8->constInt(0, false),
                $flag
            );
            self::$flags[$cacheKey] = $flag;
        }

        return self::$flags[$cacheKey];
    }

    public static function markAssigned(Context $context, string $key): void
    {
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store($i8->constInt(1, false), self::ensureFlag($context, $key));
        $cfg = $context->jitCurrentBlock;
        if ($cfg instanceof Block) {
            $id = spl_object_id($cfg);
            if (!isset(self::$cfgBlockAssigned[$id])) {
                self::$cfgBlockAssigned[$id] = [];
            }
            // flagKey may be "{main}\0name" or plain name — store the resolved CV name.
            $cvName = str_starts_with($key, '{main}'."\0")
                ? substr($key, strlen('{main}'."\0"))
                : $key;
            self::$cfgBlockAssigned[$id][$cvName] = true;
        }
        if (self::isInsertInOwningEntryBlock($context, $key)) {
            self::$definitelyAssigned[self::definiteCacheKey($context, $key)] = true;
        }
    }

    /**
     * True when an earlier assign reaches the current CFG block on every path —
     * entry prologue, or a mid-function for-init that dominates the loop header
     * (unless unset; unset currently leaves the runtime flag set, #21940).
     */
    public static function isDefinitelyAssigned(Context $context, string $key): bool
    {
        $cacheKey = self::definiteCacheKey($context, $key);
        if (isset(self::$definitelyAssigned[$cacheKey])) {
            return true;
        }
        $cvName = str_starts_with($key, '{main}'."\0")
            ? substr($key, strlen('{main}'."\0"))
            : $key;

        return self::cfgNameAssignedOnAllPaths($context, $cvName);
    }

    public static function isAssignedCondition(Context $context, string $key): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $loaded = $context->builder->load(self::ensureFlag($context, $key));

        return $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $loaded,
            $i8->constInt(0, false)
        );
    }

    /**
     * Cached immediate-dominator map keyed by root block spl_object_id.
     * Stores only idom[blockId] = parentId (O(blocks) memory) — full dominator
     * sets OOMed large TUs (#36386 / g07).
     *
     * @var array<int, array<int, int>>
     */
    private static array $cfgIdomCache = [];

    /**
     * True when a CFG block that assigned $cvName dominates the current CFG block.
     * Uses {@see Block::$parents} as predecessors (available before JIT edges exist).
     */
    private static function cfgNameAssignedOnAllPaths(Context $context, string $cvName): bool
    {
        $current = $context->jitCurrentBlock;
        if (!$current instanceof Block) {
            return false;
        }
        $assignBlocks = [];
        foreach (self::$cfgBlockAssigned as $id => $names) {
            if (isset($names[$cvName])) {
                $assignBlocks[$id] = true;
            }
        }
        if ([] === $assignBlocks) {
            return false;
        }
        $curId = spl_object_id($current);
        if (isset($assignBlocks[$curId])) {
            return true;
        }
        $root = $context->jitFunctionRootBlock ?? $current;
        $rootId = spl_object_id($root);
        if (!isset(self::$cfgIdomCache[$rootId])) {
            $blocks = self::collectCfgBlocks($root);
            if ([] === $blocks) {
                $blocks = [$current];
            }
            self::$cfgIdomCache[$rootId] = self::cfgImmediateDominators($blocks, $root);
        }
        $idom = self::$cfgIdomCache[$rootId];
        foreach ($assignBlocks as $aid => $_) {
            if (self::idomDominates($idom, $rootId, $aid, $curId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk the idom tree: $a dominates $b iff $a is $b or an ancestor of $b.
     *
     * @param array<int, int> $idom
     */
    private static function idomDominates(array $idom, int $entryId, int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $guard = 0;
        $cur = $b;
        while ($cur !== $entryId && $guard < 10000) {
            ++$guard;
            if (!isset($idom[$cur])) {
                return false;
            }
            $cur = $idom[$cur];
            if ($cur === $a) {
                return true;
            }
        }

        return $a === $entryId;
    }

    /**
     * Iterative immediate-dominator computation (Cooper/Harvey/Kennedy style).
     *
     * @param list<Block> $blocks
     * @return array<int, int> blockId => idom blockId
     */
    private static function cfgImmediateDominators(array $blocks, Block $entry): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            $ids[spl_object_id($b)] = $b;
        }
        $entryId = spl_object_id($entry);
        if (!isset($ids[$entryId])) {
            $ids[$entryId] = $entry;
            $blocks[] = $entry;
        }
        /** @var array<int, int> $idom */
        $idom = [$entryId => $entryId];
        $changed = true;
        $guard = 0;
        while ($changed && $guard < 128) {
            ++$guard;
            $changed = false;
            foreach ($blocks as $b) {
                $id = spl_object_id($b);
                if ($id === $entryId) {
                    continue;
                }
                $newIdom = null;
                foreach ($b->parents as $p) {
                    if (!$p instanceof Block) {
                        continue;
                    }
                    $pid = spl_object_id($p);
                    if (!isset($idom[$pid])) {
                        continue;
                    }
                    if (null === $newIdom) {
                        $newIdom = $pid;
                    } else {
                        $newIdom = self::idomIntersect($idom, $newIdom, $pid);
                    }
                }
                if (null === $newIdom) {
                    // No processed preds yet — leave unset until a later pass.
                    continue;
                }
                if (!isset($idom[$id]) || $idom[$id] !== $newIdom) {
                    $idom[$id] = $newIdom;
                    $changed = true;
                }
            }
        }

        return $idom;
    }

    /**
     * @param array<int, int> $idom
     */
    private static function idomIntersect(array $idom, int $b1, int $b2): int
    {
        $ancestors = [];
        $a = $b1;
        $guard = 0;
        while ($guard < 10000) {
            ++$guard;
            $ancestors[$a] = true;
            if (!isset($idom[$a]) || $idom[$a] === $a) {
                break;
            }
            $a = $idom[$a];
        }
        $b = $b2;
        $guard = 0;
        while ($guard < 10000) {
            ++$guard;
            if (isset($ancestors[$b])) {
                return $b;
            }
            if (!isset($idom[$b]) || $idom[$b] === $b) {
                break;
            }
            $b = $idom[$b];
        }

        return $b1;
    }

    /**
     * @return list<Block>
     */
    private static function collectCfgBlocks(Block $root): array
    {
        $seen = new \SplObjectStorage();
        $out = [];
        $stack = [$root];
        while ([] !== $stack) {
            $b = array_pop($stack);
            if (!$b instanceof Block || $seen->contains($b)) {
                continue;
            }
            $seen->attach($b);
            $out[] = $b;
            foreach ($b->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
            foreach ($b->blocks as $sub) {
                if ($sub instanceof Block) {
                    $stack[] = $sub;
                }
            }
        }

        return $out;
    }

    private static function definiteCacheKey(Context $context, string $key): string
    {
        $moduleId = spl_object_id($context->module);
        if (str_starts_with($key, '{main}'."\0")) {
            return $moduleId."\0".$key;
        }

        return $moduleId."\0".spl_object_id(self::ownerFunctionForScopeFlags($context))."\0".$key;
    }

    private static function isInsertInOwningEntryBlock(Context $context, string $key): bool
    {
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $insert) {
            return false;
        }
        if (str_starts_with($key, '{main}'."\0")) {
            $owner = BasicBlockHelper::parentFunction($context);
        } else {
            $owner = self::ownerFunctionForScopeFlags($context);
        }
        if ($owner->countBasicBlocks() < 1) {
            return false;
        }
        $entry = $owner->getEntryBasicBlock();
        // Fresh BasicBlock wrappers are not ===; compare LLVM names within the owner.
        if ($insert->getName() !== $entry->getName()) {
            return false;
        }
        $insertParent = $insert->getParent();
        if (!$insertParent instanceof Function_) {
            return false;
        }

        return TryCatchHelper::sameLlvmFunction($insertParent, $owner);
    }

    private static function ensureMainModuleFlag(Context $context, string $key): Value
    {
        if (!isset(self::$mainFlags[$key])) {
            $i8 = $context->getTypeFromString('int8');
            $flagName = 'phpc_scope_var_init_'.substr(hash('sha256', $key), 0, 16);
            $flag = $context->module->addGlobal($i8, $flagName);
            $flag->setInitializer($i8->constInt(0, false));
            self::$mainFlags[$key] = $flag;
        }

        return self::$mainFlags[$key];
    }

    /**
     * Use the CFG root LLVM function when the insert block is in that function; otherwise
     * parentFunction() (nested helpers / callee emission must not write root entry allocas).
     */
    private static function ownerFunctionForScopeFlags(Context $context): Function_
    {
        $insertOwner = BasicBlockHelper::parentFunction($context);
        $rootBlock = $context->jitFunctionRootBlock;
        if (null === $rootBlock || null === $rootBlock->func) {
            return $insertOwner;
        }
        $scoped = strtolower($rootBlock->func->getScopedName());
        if ('' === $scoped || !isset($context->functions[$scoped])) {
            return $insertOwner;
        }
        $rootFn = $context->functions[$scoped];
        if (!$rootFn instanceof Function_) {
            return $insertOwner;
        }
        if (!TryCatchHelper::sameLlvmFunction($insertOwner, $rootFn)) {
            return $insertOwner;
        }

        return $rootFn;
    }
}
