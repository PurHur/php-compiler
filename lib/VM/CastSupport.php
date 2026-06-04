<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * (array) cast lowering for VM (issue #3328, Zend cast_object / convert_to_array).
 */
final class CastSupport
{
    /**
     * @param array<string, ClassEntry>|null $classesByLc
     */
    public static function toArray(Variable $src, ?array $classesByLc = null): Variable
    {
        $src = $src->resolveIndirect();
        $result = new Variable();

        if (Variable::TYPE_ARRAY === $src->type) {
            $result->array($src->toArray()->replaceCopy());

            return $result;
        }

        if (Variable::TYPE_OBJECT === $src->type) {
            $result->newArray();
            self::objectToArray($src->toObject(), $result->toArray(), $classesByLc ?? []);

            return $result;
        }

        if (Variable::TYPE_NULL === $src->type) {
            $result->newArray();

            return $result;
        }

        if (Variable::TYPE_BOOLEAN === $src->type && !$src->toBool()) {
            $result->newArray();

            return $result;
        }

        $result->newArray();
        $copy = new Variable();
        $copy->copyFrom($src);
        $result->toArray()->append($copy);

        return $result;
    }

    /**
     * @param array<string, ClassEntry> $classesByLc
     */
    private static function objectToArray(ObjectEntry $obj, HashTable $ht, array $classesByLc): void
    {
        if ('stdClass' === $obj->class->name) {
            self::appendRawProperties($obj, $ht, null);

            return;
        }

        $declared = [];
        foreach ($obj->class->properties as $meta) {
            if (!$obj->hasProperty($meta->name)) {
                continue;
            }
            $value = $obj->getProperty($meta->name)->resolveIndirect();
            if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $declared[$meta->name] = true;
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->add(PropertyMangle::propertyKey($meta, $classesByLc), $copy);
        }

        self::appendRawProperties($obj, $ht, $declared);
    }

    /**
     * @param array<string, true>|null $skipNames
     */
    private static function appendRawProperties(ObjectEntry $obj, HashTable $ht, ?array $skipNames): void
    {
        foreach ($obj->getRawProperties() as $name => $prop) {
            if (null !== $skipNames && isset($skipNames[$name])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->add($name, $copy);
        }
    }
}
