<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** VM pack()/unpack() — pack via PackEngine; unpack via UnpackEngine (issues #5231, #5442). */
final class VmPack
{
    /**
     * @param list<mixed|Variable> $args values after format string
     */
    public static function pack(string $format, array $args, ?Frame $frame = null): string
    {
        return PackEngine::pack($format, $args, $frame);
    }

    /**
     * @return array<int|string, int|float|string>|false
     */
    public static function unpack(string $format, string $data, int $offset = 0): array|false
    {
        return UnpackEngine::unpack($format, $data, $offset);
    }

    public static function unpackToHashTable(string $format, string $data, int $offset = 0): ?HashTable
    {
        $result = self::unpack($format, $data, $offset);
        if (false === $result) {
            return null;
        }

        return self::importUnpackResult($result);
    }

    /**
     * @param array<int|string, int|float|string> $result
     */
    public static function importUnpackResult(array $result): HashTable
    {
        $ht = new HashTable();
        foreach ($result as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_float($value)) {
                $slot->float($value);
            } elseif (\is_string($value)) {
                $slot->string($value);
            } else {
                throw new \LogicException('unpack() result type not supported in this compiler build');
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add($key, $slot);
            }
        }

        return $ht;
    }
}
