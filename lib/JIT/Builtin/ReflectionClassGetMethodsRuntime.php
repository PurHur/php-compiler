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
 * Thin AOT materialization for ReflectionClass::getMethods() (#34107).
 *
 * ExternalMethod previously returned NULL. Build a packed list of constructed
 * ReflectionMethod objects from compile-unit {@see Type\Object_} method tables.
 *
 * php-src: zim_ReflectionClass_getMethods / add_reflection_method_sub
 */
final class ReflectionClassGetMethodsRuntime
{
    public static function emitForClassId(Context $context, int $classId, int $filter): Value
    {
        $object = $context->type->object;
        $specs = $object->allMethodsForReflection($classId, $filter);
        $ht = HashTableHelper::alloc($context);
        $n = \count($specs);
        if ($n > 0) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $context->constantFromInteger($n, 'size_t')
            );
        }
        $rmClassId = $object->lookup('ReflectionMethod');
        foreach ($specs as $i => $spec) {
            $rmObj = $object->allocate($rmClassId);
            ReflectionSetup::markConstructed($context, $rmObj);
            $decl = (string) $spec['declaringClass'];
            $name = (string) $spec['display'];
            $declStr = $context->builder->load($context->constantStringFromString($decl));
            $nameStr = $context->builder->load($context->constantStringFromString($name));
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rmObj,
                'ReflectionMethod',
                ReflectionSupport::PROP_REFLECTION_METHOD_CLASS,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $declStr)
            );
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rmObj,
                'ReflectionMethod',
                ReflectionSupport::PROP_REFLECTION_METHOD_FUNC,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr)
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->constantFromInteger($i, 'int64'),
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rmObj)
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
