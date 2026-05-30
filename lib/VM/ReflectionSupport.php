<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Build ReflectionAttribute stubs from compile-time metadata (#1936, #3800).
 */
final class ReflectionSupport
{
    public const REFLECTION_CLASS = 'reflectionclass';

    public const REFLECTION_METHOD = 'reflectionmethod';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

    public const REFLECTION_ENUM_UNIT_CASE = 'reflectionenumunitcase';

    public const PROP_CLASS_NAME = 'name';

    public const PROP_METHOD_NAME = 'method';

    public const PROP_ATTR_NAME = 'name';

    public const PROP_ATTR_ARGS = 'args';

    public const PROP_ENUM_CASE_NAME = 'case';

    /**
     * @param list<string> $names
     */
    public static function attributesArray(Frame $frame, array $names): Variable
    {
        $metadata = [];
        foreach ($names as $name) {
            $metadata[] = new AttributeMetadata($name);
        }

        return self::attributesArrayFromMetadata($frame, $metadata);
    }

    /**
     * @param list<AttributeMetadata> $metadata
     */
    public static function attributesArrayFromMetadata(Frame $frame, array $metadata): Variable
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('Reflection requires VM context');
        }
        $attrClass = $ctx->classes[self::REFLECTION_ATTRIBUTE] ?? null;
        if (null === $attrClass) {
            throw new \LogicException('ReflectionAttribute is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($metadata as $meta) {
            $obj = new ObjectEntry($attrClass);
            $obj->constructed = true;
            $obj->getProperty(self::PROP_ATTR_NAME)->string($meta->name);
            $argsVar = new Variable();
            $argsVar->newArray();
            $argsHt = $argsVar->toArray();
            foreach ($meta->args as $arg) {
                $slot = new Variable();
                $slot->copyFrom($arg);
                $argsHt->append($slot);
            }
            $obj->getProperty(self::PROP_ATTR_ARGS)->copyFrom($argsVar);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    public static function requireReflectionClass(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionClass method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_CLASS) {
            throw new \LogicException('Expected ReflectionClass instance');
        }

        return $obj;
    }

    public static function requireReflectionMethod(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionMethod method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_METHOD) {
            throw new \LogicException('Expected ReflectionMethod instance');
        }

        return $obj;
    }

    public static function requireReflectionAttribute(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionAttribute method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ATTRIBUTE) {
            throw new \LogicException('Expected ReflectionAttribute instance');
        }

        return $obj;
    }

    public static function requireReflectionEnumUnitCase(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionEnumUnitCase method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ENUM_UNIT_CASE) {
            throw new \LogicException('Expected ReflectionEnumUnitCase instance');
        }

        return $obj;
    }

    public static function classNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionClass missing target class name');
        }

        return $nameVar->toString();
    }

    public static function enumCaseNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_ENUM_CASE_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionEnumUnitCase missing case name');
        }

        return $nameVar->toString();
    }

    public static function methodNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_METHOD_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionMethod missing method name');
        }

        return $nameVar->toString();
    }

    /**
     * @return list<Variable>
     */
    public static function attributeArgsFromReflection(ObjectEntry $reflection): array
    {
        $argsVar = $reflection->getProperty(self::PROP_ATTR_ARGS)->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            return [];
        }
        $out = [];
        foreach ($argsVar->toArray()->iterate(true) as $arg) {
            $out[] = $arg;
        }

        return $out;
    }

    /**
     * @param list<string> $all
     *
     * @return list<string>
     */
    public static function filterByName(array $all, ?string $filter): array
    {
        if (null === $filter || '' === $filter) {
            return $all;
        }
        $want = strtolower(ltrim($filter, '\\'));
        $out = [];
        foreach ($all as $name) {
            $cand = strtolower(ltrim($name, '\\'));
            if ($cand === $want || str_ends_with($cand, '\\'.$want)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @param list<AttributeMetadata> $all
     *
     * @return list<AttributeMetadata>
     */
    public static function filterMetadataByName(array $all, ?string $filter): array
    {
        if (null === $filter || '' === $filter) {
            return $all;
        }
        $want = strtolower(ltrim($filter, '\\'));
        $out = [];
        foreach ($all as $meta) {
            $cand = strtolower(ltrim($meta->name, '\\'));
            if ($cand === $want || str_ends_with($cand, '\\'.$want)) {
                $out[] = $meta;
            }
        }

        return $out;
    }
}
