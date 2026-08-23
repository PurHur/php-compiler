<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin\Type\ObjectStaticPropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Thin AOT materialization for ReflectionClass::getStaticProperties() (#34118).
 *
 * ExternalMethod previously returned NULL. Build a name=>value map from live
 * compile-unit static property globals (skip parent-privates / uninitialized typed).
 *
 * php-src: zim_ReflectionClass_getStaticProperties
 */
final class ReflectionClassGetStaticPropertiesRuntime
{
    public static function emitForClassId(Context $context, int $classId): Value
    {
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $globals = $object->staticPropertyGlobalsForClass($classId);
        foreach ($globals as $key => $entry) {
            $display = (string) ($entry['displayName'] ?? $key);
            $meta = $object->staticPropertyVisibilityMeta($classId, $display);
            if (null === $meta) {
                continue;
            }
            // php-src: parent-private statics are not visible on the child (#6948 / #34118).
            if (($meta['visibility'] & \PHPCfg\Func::FLAG_PRIVATE) !== 0
                && (int) $meta['declaringClassId'] !== $classId) {
                continue;
            }
            // VM TypedPropertyCheck::isUninitialized — omit uninitialized typed statics.
            if (!empty($entry['typedWithoutDefault'])) {
                continue;
            }
            $fetched = ObjectStaticPropertyLlvm::fetch($object, $classId, $display, false);
            $keyStr = $context->builder->load($context->constantStringFromString($display));
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $fetched);
        }

        return self::wrapHashTable($context, $ht);
    }

    public static function emitEmpty(Context $context): Value
    {
        return self::wrapHashTable($context, HashTableHelper::alloc($context));
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
