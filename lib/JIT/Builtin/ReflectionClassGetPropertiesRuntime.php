<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * Thin AOT materialization for ReflectionClass::getProperties() (#34113).
 *
 * ExternalMethod previously returned NULL. Build a packed list of constructed
 * ReflectionProperty objects from compile-unit {@see Type\Object_} property tables.
 *
 * php-src: zim_ReflectionClass_getProperties / add_reflection_property
 */
final class ReflectionClassGetPropertiesRuntime
{
    public static function emitForClassId(Context $context, int $classId, int $filter): Value
    {
        $object = $context->type->object;
        $specs = $object->allPropertiesForReflection($classId, $filter);
        $ht = HashTableHelper::alloc($context);
        $n = \count($specs);
        if ($n > 0) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $context->constantFromInteger($n, 'size_t')
            );
        }
        $rpClassId = $object->lookup('ReflectionProperty');
        foreach ($specs as $i => $spec) {
            $rpObj = $object->allocate($rpClassId);
            ReflectionSetup::markConstructed($context, $rpObj);
            $decl = (string) $spec['declaringClass'];
            $name = (string) $spec['display'];
            $declStr = $context->builder->load($context->constantStringFromString($decl));
            $nameStr = $context->builder->load($context->constantStringFromString($name));
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rpObj,
                'ReflectionProperty',
                ReflectionSupport::PROP_PROPERTY_NAME,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr)
            );
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rpObj,
                'ReflectionProperty',
                ReflectionSupport::PROP_DECLARING_CLASS_NAME,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $declStr)
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->constantFromInteger($i, 'int64'),
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rpObj)
            );
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
