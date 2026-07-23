<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Array-backed storage for Ds\Vector / Ds\Map / Ds\Set MVP (#22549).
 *
 * PECL: php-ds/ext-ds — correctness-first; not capacity/perf tuned.
 */
final class VmDsStorage
{
    public const VECTOR_LC = 'ds\\vector';

    public const MAP_LC = 'ds\\map';

    public const SET_LC = 'ds\\set';

    /** @var array<int, HashTable> */
    private static array $vectors = [];

    /** @var array<int, HashTable> */
    private static array $maps = [];

    /** @var array<int, HashTable> */
    private static array $sets = [];

    public static function initVector(ObjectEntry $object, HashTable $values): void
    {
        self::$vectors[$object->id] = self::reindexList($values);
    }

    public static function initMap(ObjectEntry $object, HashTable $values): void
    {
        self::$maps[$object->id] = $values->duplicate();
    }

    public static function initSet(ObjectEntry $object, HashTable $values): void
    {
        self::$sets[$object->id] = self::uniqueValues($values);
    }

    public static function vectorTable(ObjectEntry $object): HashTable
    {
        return self::$vectors[$object->id] ?? new HashTable();
    }

    public static function mapTable(ObjectEntry $object): HashTable
    {
        return self::$maps[$object->id] ?? new HashTable();
    }

    public static function setTable(ObjectEntry $object): HashTable
    {
        return self::$sets[$object->id] ?? new HashTable();
    }

    public static function requireArrayArg(Variable $arg, string $function, int $argNum): HashTable
    {
        $var = $arg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d must be of type array, %s given',
                $function,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_DOUBLE => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    Variable::TYPE_RESOURCE => 'resource',
                    default => 'mixed',
                }
            ));
        }

        return $var->toArray();
    }

    public static function receiver(Frame $frame, string $classLc, string $method): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \Error($method.'(): missing $this');
        }
        $thisVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thisVar->type) {
            throw new \Error($method.'(): $this is not an object');
        }
        $object = $thisVar->toObject();
        if (\strtolower($object->class->name) !== $classLc) {
            throw new \TypeError($method.'(): $this is not a '.$object->class->name);
        }

        return $object;
    }

    private static function reindexList(HashTable $src): HashTable
    {
        $out = new HashTable();
        foreach ($src->iterate() as $value) {
            $copy = new Variable();
            $copy->copyFrom($value->resolveIndirect());
            $out->append($copy);
        }

        return $out;
    }

    private static function uniqueValues(HashTable $src): HashTable
    {
        $out = new HashTable();
        $seen = [];
        foreach ($src->iterate() as $value) {
            $val = $value->resolveIndirect();
            $key = self::valueIdentity($val);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $copy = new Variable();
            $copy->copyFrom($val);
            $out->append($copy);
        }

        return $out;
    }

    public static function valueIdentity(Variable $var): string
    {
        $v = $var->resolveIndirect();

        return match ($v->type) {
            Variable::TYPE_NULL => 'n',
            Variable::TYPE_BOOLEAN => 'b:'.($v->toBool() ? '1' : '0'),
            Variable::TYPE_INTEGER => 'i:'.$v->toInt(),
            Variable::TYPE_DOUBLE => 'f:'.$v->toFloat(),
            Variable::TYPE_STRING => 's:'.$v->toString(),
            Variable::TYPE_OBJECT => 'o:'.$v->toObject()->id,
            Variable::TYPE_RESOURCE => 'r:'.$v->toInt(),
            default => 'x:'.\spl_object_id($v),
        };
    }

    public static function mapGet(ObjectEntry $object, Variable $key, ?Variable $default = null): Variable
    {
        $table = self::mapTable($object);
        $found = $table->findVariable($key->resolveIndirect(), false);
        if (null !== $found && !$found->isUndefined()) {
            $out = new Variable();
            $out->copyFrom($found->resolveIndirect());

            return $out;
        }
        if (null !== $default) {
            $out = new Variable();
            $out->copyFrom($default->resolveIndirect());

            return $out;
        }
        throw new \OutOfBoundsException('Key not found');
    }

    public static function setContains(ObjectEntry $object, Variable $value): bool
    {
        $want = self::valueIdentity($value);
        foreach (self::setTable($object)->iterate() as $entry) {
            if (self::valueIdentity($entry) === $want) {
                return true;
            }
        }

        return false;
    }

    public static function setAdd(ObjectEntry $object, Variable $value): void
    {
        if (self::setContains($object, $value)) {
            return;
        }
        if (!isset(self::$sets[$object->id])) {
            self::$sets[$object->id] = new HashTable();
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$sets[$object->id]->append($copy);
    }
}
