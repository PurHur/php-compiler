<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ClassConstVisibility;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * Thin AOT materialization for ReflectionClass::getReflectionConstants() (#34119).
 *
 * ExternalMethod previously returned NULL. Build a packed list of constructed
 * ReflectionClassConstant objects from compile-unit class constant tables
 * (peer getConstants #34109).
 *
 * php-src: zim_ReflectionClass_getReflectionConstants / add_class_constant
 */
final class ReflectionClassGetReflectionConstantsRuntime
{
    public static function emitForClassId(Context $context, int $classId, int $filter): Value
    {
        $object = $context->type->object;
        $specs = self::specsForClassId($object, $classId, $filter);
        $ht = HashTableHelper::alloc($context);
        $n = \count($specs);
        if ($n > 0) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $context->constantFromInteger($n, 'size_t')
            );
        }
        $rccClassId = $object->lookup('ReflectionClassConstant');
        foreach ($specs as $i => $spec) {
            $rcObj = $object->allocate($rccClassId);
            ReflectionSetup::markConstructed($context, $rcObj);
            $decl = (string) $spec['declaringClass'];
            $name = (string) $spec['display'];
            $declStr = $context->builder->load($context->constantStringFromString($decl));
            $nameStr = $context->builder->load($context->constantStringFromString($name));
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rcObj,
                'ReflectionClassConstant',
                ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr)
            );
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rcObj,
                'ReflectionClassConstant',
                ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $declStr)
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->constantFromInteger($i, 'int64'),
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rcObj)
            );
        }

        return self::wrapHashTable($context, $ht);
    }

    public static function emitEmpty(Context $context): Value
    {
        return self::wrapHashTable($context, HashTableHelper::alloc($context));
    }

    /**
     * @return list<array{display: string, declaringClass: string}>
     */
    private static function specsForClassId(\PHPCompiler\JIT\Builtin\Type\Object_ $object, int $classId, int $filter): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<array{display: string, declaringClass: string}> $out */
        $out = [];
        $reflectedLc = strtolower(ltrim($object->classNameForId($classId), '\\'));
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            foreach ($object->classConstantsForId($currentId) as [$key, $_entry]) {
                if (!\is_string($key) || '' === $key || isset($seen[$key])) {
                    continue;
                }
                $vis = ClassConstVisibility::mask($object->constVisibility($currentId, $key));
                $declName = $object->classNameForId($currentId);
                $declLc = strtolower(ltrim($declName, '\\'));
                // php-src: parent-private constants hidden on child (#34109 / #34119).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $declLc !== $reflectedLc) {
                    $seen[$key] = true;
                    continue;
                }
                if (!self::matchesFilter($vis, $filter)) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'display' => $object->classConstDisplayName($currentId, $key),
                    'declaringClass' => $declName,
                ];
            }
            $parentName = $object->parentClassDisplayName($object->classNameForId($currentId));
            if (null === $parentName) {
                break;
            }
            $currentId = $object->lookup($parentName);
        }

        return $out;
    }

    private static function matchesFilter(int $cfgVisibility, int $filter): bool
    {
        if (0 === $filter) {
            return true;
        }
        if (($cfgVisibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $flags = \PHPCfg\Func::FLAG_PRIVATE;
        } elseif (($cfgVisibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            $flags = \PHPCfg\Func::FLAG_PROTECTED;
        } else {
            $flags = \PHPCfg\Func::FLAG_PUBLIC;
        }

        return ($flags & $filter) !== 0;
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
