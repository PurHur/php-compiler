<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

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
    private static function ownerFunctionForScopeFlags(Context $context): \PHPLLVM\Value\Function_
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
        if (!$rootFn instanceof \PHPLLVM\Value\Function_) {
            return $insertOwner;
        }
        if (!TryCatchHelper::sameLlvmFunction($insertOwner, $rootFn)) {
            return $insertOwner;
        }

        return $rootFn;
    }
}
