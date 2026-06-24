<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** VM helpers for key/current/next/prev/reset/end (ext/standard/array.c; #4967). */
final class VmArrayPointer
{
    public static function returnKey(Frame $frame, ?Variable $key): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $key) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($key);
    }

    public static function returnValue(Frame $frame, ?Variable $value): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $value) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($value);
    }

    /**
     * @throws \TypeError when {@param $value} is not an array or object
     */
    public static function requirePointerTarget(Variable $value, string $fn, bool $mutator): VmPointerTarget
    {
        if (Variable::TYPE_INDIRECT === $value->type) {
            $value = $value->resolveIndirect();
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            if ($mutator) {
                return self::fromArray(self::requireByRefArray($value, $fn));
            }

            return self::fromArray(VmArray::requireArray($value, $fn));
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            return self::fromObject($value->toObject());
        }

        throw new \TypeError(
            \sprintf('%s(): Argument #1 ($array) must be of type array, %s given', $fn, self::typeLabel($value))
        );
    }

    public static function fromArray(HashTable $array): VmPointerTarget
    {
        return VmPointerTarget::fromArray($array);
    }

    public static function fromObject(ObjectEntry $object): VmPointerTarget
    {
        return VmPointerTarget::fromObject($object);
    }

    /**
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireByRefArray(Variable $value, string $fn): HashTable
    {
        if (Variable::TYPE_INDIRECT === $value->type) {
            $value = $value->resolveIndirect();
        }
        if (Variable::TYPE_ARRAY !== $value->type) {
            throw new \TypeError(
                \sprintf('%s(): Argument #1 ($array) must be of type array, %s given', $fn, self::typeLabel($value))
            );
        }

        return $value->toArray();
    }

    private static function typeLabel(Variable $value): string
    {
        $v = $value->resolveIndirect();

        return match ($v->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
