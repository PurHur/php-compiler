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
 * Thin AOT materialization for ReflectionClass::{getInterfaces,getTraits} (#34121).
 *
 * ExternalMethod previously returned NULL. Build FQCN⇒ReflectionClass maps from
 * compile-unit {@see Type\Object_} interface/trait tables (peer NameList #34110).
 *
 * php-src: zim_ReflectionClass_getInterfaces / getTraits
 */
final class ReflectionClassClassMapRuntime
{
    public static function emitForClassId(Context $context, int $classId, string $kindLc): Value
    {
        $object = $context->type->object;
        $display = $object->classNameForId($classId);
        $lc = strtolower(ltrim((string) $display, '\\'));
        if ('' === $lc) {
            return self::emitEmpty($context);
        }

        if ('interfaces' === $kindLc) {
            $names = [];
            foreach ($object->interfacesForClassImplementsLc($lc) as $ifaceLc) {
                $ifaceLc = strtolower(ltrim((string) $ifaceLc, '\\'));
                if ('' === $ifaceLc) {
                    continue;
                }
                $names[] = self::displayNameForLc($object, $ifaceLc);
            }
        } else {
            $names = array_values($object->usedTraitNamesForClassLc($lc));
        }

        return self::emitReflectionClassMap($context, $names);
    }

    public static function emitEmpty(Context $context): Value
    {
        return self::wrapHashTable($context, HashTableHelper::alloc($context));
    }

    /**
     * @param list<string> $names
     */
    private static function emitReflectionClassMap(Context $context, array $names): Value
    {
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $n = \count($names);
        if ($n > 0) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $context->constantFromInteger($n, 'size_t')
            );
        }
        $rcClassId = $object->lookup('ReflectionClass');
        foreach ($names as $name) {
            $rcObj = $object->allocate($rcClassId);
            ReflectionSetup::markConstructed($context, $rcObj);
            $nameStr = $context->builder->load($context->constantStringFromString($name));
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rcObj,
                'ReflectionClass',
                ReflectionSupport::PROP_CLASS_NAME,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr)
            );
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            HashTableHelper::setAtStringKey(
                $context,
                $ht,
                $keyStr,
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rcObj)
            );
        }

        return self::wrapHashTable($context, $ht);
    }

    private static function displayNameForLc(\PHPCompiler\JIT\Builtin\Type\Object_ $object, string $ifaceLc): string
    {
        foreach ($object->allClassNamesById() as $iid => $iname) {
            $iid = (int) $iid;
            $idisp = $object->classNameForId($iid);
            if (!\is_string($idisp) || '' === $idisp) {
                $idisp = \is_string($iname) ? $iname : '';
            }
            if (strtolower(ltrim($idisp, '\\')) === $ifaceLc) {
                return $idisp;
            }
        }

        return $ifaceLc;
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
