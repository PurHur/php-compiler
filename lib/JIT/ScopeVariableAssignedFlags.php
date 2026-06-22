<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/** Per-scope-variable assigned flags for JIT undefined-variable guards (#10360). */
final class ScopeVariableAssignedFlags
{
    /** @var array<string, Value> */
    private static array $flags = [];

    public static function flagKey(Context $context, string $name): string
    {
        return $context->activeFunction."\0".$context->resolveRefAliasName($name);
    }

    public static function ensureFlag(Context $context, string $key): Value
    {
        if (!isset(self::$flags[$key])) {
            $i8 = $context->getTypeFromString('int8');
            $flagName = 'phpc_scope_var_init_'.substr(hash('sha256', $key), 0, 16);
            $flag = $context->module->addGlobal($i8, $flagName);
            $flag->setInitializer($i8->constInt(0, false));
            self::$flags[$key] = $flag;
        }

        return self::$flags[$key];
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
}
