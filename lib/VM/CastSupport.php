<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\simplexml\SimpleXmlJsonExport;
use PHPCompiler\ext\spl\SplArrayStorage;

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

        if (Variable::TYPE_ENUM_CASE === $src->type) {
            $result->newArray();
            self::enumCaseEntryToArray($src->toEnumCase(), $result->toArray());

            return $result;
        }

        if (ResourceSupport::isVmResource($src)) {
            return self::singletonArrayCast($src);
        }

        if (Variable::TYPE_OBJECT === $src->type) {
            $obj = $src->toObject();
            if (EnumCaseSupport::isEnumCase($obj)) {
                $result->newArray();
                self::enumCaseObjectToArray($obj, $result->toArray());

                return $result;
            }
            if (null !== $obj->closureState) {
                return self::singletonArrayCast($src);
            }
            // ArrayObject/ArrayIterator: (array) uses backing storage unless STD_PROP_LIST (#19631).
            $splCast = SplArrayStorage::arrayCastDuplicate($obj);
            if (null !== $splCast) {
                $result->array($splCast);

                return $result;
            }
            // SimpleXMLElement: @attributes + children (php-src sxe_object_cast_ex; #21666).
            if (SimpleXmlJsonExport::handles($obj)) {
                return SimpleXmlJsonExport::exportZendArrayCast($obj);
            }
            // DateTime*/DateTimeZone/DateInterval/DatePeriod: Zend date wire (#22424, #22425, #22435).
            $dateCast = self::tryDateObjectArrayCast($obj);
            if (null !== $dateCast) {
                $result->array($dateCast);

                return $result;
            }
            $result->newArray();
            self::objectToArray($obj, $result->toArray(), $classesByLc ?? []);

            return $result;
        }

        if (Variable::TYPE_NULL === $src->type) {
            $result->newArray();

            return $result;
        }

        // Zend convert_to_array: IS_FALSE / IS_TRUE / scalars wrap as [0 => value];
        // only IS_NULL yields empty (#30097 — false must not share null's empty path).
        $result->newArray();
        $copy = new Variable();
        $copy->copyFrom($src);
        $result->toArray()->append($copy);

        return $result;
    }

    /** Zend convert_to_array(IS_RESOURCE) — one-element array with live/closed resource at index 0 (#15012, #15013). */
    public static function vmResourceArrayCast(Variable $src): Variable
    {
        return self::singletonArrayCast($src);
    }

    /**
     * Zend convert_to_array — singleton array for resource/closure pseudo-objects (#15012, #15015).
     */
    private static function singletonArrayCast(Variable $src): Variable
    {
        $result = new Variable();
        $result->newArray();
        $copy = new Variable();
        $copy->copyFrom($src->resolveIndirect());
        $result->toArray()->append($copy);

        return $result;
    }

    /** Zend {@see zend_enum_to_array()} — unit/backed enum case (array) cast (#5536). */
    private static function enumCaseEntryToArray(EnumCaseEntry $entry, HashTable $ht): void
    {
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($entry->caseName);
        $ht->add('name', $nameVar);
        if (null !== $entry->enumClass->backedType) {
            $valueVar = new Variable();
            $valueVar->copyFrom($entry->backingValue);
            $ht->add('value', $valueVar);
        }
    }

    private static function enumCaseObjectToArray(ObjectEntry $obj, HashTable $ht): void
    {
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($obj->enumCaseName ?? '');
        $ht->add('name', $nameVar);
        if (null !== $obj->class->backedType && null !== $obj->enumCaseValue) {
            $valueVar = new Variable();
            $valueVar->copyFrom($obj->enumCaseValue);
            $ht->add('value', $valueVar);
        }
    }

    /**
     * php-src ext/date/php_date.c — date_object_get_properties / (array) cast (#22424, #22425, #22435).
     *
     * Reuses the same Zend wire as serialize/var_export
     * ({@see DateTimeSupport::varExportPropertyMap}, {@see DateIntervalSupport::varExportPropertyMap},
     * {@see DatePeriodSupport::varExportPropertyMap}).
     */
    public static function tryDateObjectArrayCast(ObjectEntry $obj): ?HashTable
    {
        $lc = strtolower($obj->class->name);
        $map = null;
        if (DateTimeSupport::CLASS_DATETIME === $lc || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lc) {
            $map = DateTimeSupport::varExportPropertyMap($obj);
        } elseif (DateTimeSupport::CLASS_DATETIMEZONE === $lc) {
            $map = DateTimeSupport::varExportTimezonePropertyMap($obj);
        } elseif (DateIntervalSupport::CLASS_DATEINTERVAL === $lc) {
            $map = DateIntervalSupport::varExportPropertyMap($obj);
        } elseif (DatePeriodSupport::CLASS_DATEPERIOD === $lc) {
            $map = DatePeriodSupport::varExportPropertyMap($obj);
        }
        if (null === $map) {
            return null;
        }
        $ht = new HashTable();
        foreach ($map as $name => $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->add((string) $name, $copy);
        }

        return $ht;
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
        // Zend SensitiveParameterValue — (array) cast yields empty (get_properties handler, #23042).
        if (strtolower($obj->class->name) === strtolower(SensitiveParamSupport::CLASS_NAME)) {
            return;
        }

        // Child ClassEntry::$properties lists own slots before inherited ones (ObjectEntry ctor).
        // zend_std_get_properties_for emits parent→child (parent privates, then protected/public,
        // then child privates) — same order as get_mangled_object_vars (#23451).
        $declared = [];
        /** @var array<string, true> $seenPrivate */
        $seenPrivate = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (self::classHierarchyParentFirst($obj->class, $classesByLc) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                if ($isPrivate) {
                    $privKey = ('' !== $meta->declaringClassLc
                        ? $meta->declaringClassLc
                        : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                if ($meta->phpInvisible) {
                    // Still skip raw append so (array) does not leak C-only slots (#22513).
                    $declared[$meta->name] = true;
                    continue;
                }
                if (!$obj->hasPropertyForMeta($meta)) {
                    continue;
                }
                $value = $obj->getPropertyForMeta($meta)->resolveIndirect();
                if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                    continue;
                }
                $declared[$meta->name] = true;
                $copy = new Variable();
                $copy->copyFrom($value);
                $ht->add(PropertyMangle::propertyKey($meta, $classesByLc), $copy);
            }
        }

        self::appendRawProperties($obj, $ht, $declared);
    }

    /**
     * Root-most ancestor first — matches VmReflection::classHierarchyChain + array_reverse.
     *
     * @param array<string, ClassEntry> $classesByLc
     * @return list<ClassEntry>
     */
    private static function classHierarchyParentFirst(ClassEntry $entry, array $classesByLc): array
    {
        $chain = [$entry];
        $current = $entry;
        while (null !== $current->parentLc && isset($classesByLc[$current->parentLc])) {
            $current = $classesByLc[$current->parentLc];
            $chain[] = $current;
        }

        return array_reverse($chain);
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
            // Zend convert_to_array: dynamic property names are always string keys (#7427).
            $ht->add((string) $name, $copy);
        }
    }
}
