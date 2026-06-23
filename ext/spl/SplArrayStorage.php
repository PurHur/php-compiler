<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared backing storage for SPL ArrayObject / ArrayIterator (php-src ext/spl/spl_array.c).
 */
final class SplArrayStorage
{
    /**
     * @var array<int, array{
     *   flags: int,
     *   table: HashTable,
     *   propList: array<int|string, mixed>,
     *   iteratorClass: ?string,
     *   pos: int
     * }>
     */
    private static array $store = [];

    public static function init(
        ObjectEntry $object,
        HashTable $table,
        int $flags = 0,
        ?string $iteratorClass = null,
        array $propList = []
    ): void {
        self::$store[$object->id] = [
            'flags' => $flags,
            'table' => $table,
            'propList' => $propList,
            'iteratorClass' => $iteratorClass,
            'pos' => 0,
        ];
    }

    /** @return array{flags: int, table: HashTable, propList: array<int|string, mixed>, iteratorClass: ?string, pos: int} */
    public static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SPL array object state missing');
        }

        return self::$store[$object->id];
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    public static function rewindIterator(ObjectEntry $object): void
    {
        self::$store[$object->id]['pos'] = 0;
    }

    public static function nextIterator(ObjectEntry $object): void
    {
        ++self::$store[$object->id]['pos'];
    }

    /** @return list<int|string> */
    public static function iteratorKeys(ObjectEntry $object): array
    {
        $keys = [];
        foreach (self::state($object)['table']->iterateKeyed(true) as [$keyVar, $_]) {
            $keys[] = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
        }

        return $keys;
    }

    public static function iteratorValid(ObjectEntry $object): bool
    {
        $state = self::state($object);
        $keys = self::iteratorKeys($object);

        return $state['pos'] >= 0 && $state['pos'] < \count($keys);
    }

    public static function iteratorCurrent(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if (!self::iteratorValid($object)) {
            throw new \RuntimeException('Cannot fetch current() on invalid ArrayIterator position');
        }
        $key = self::iteratorKeys($object)[$state['pos']];
        $var = \is_int($key)
            ? $state['table']->findIndex($key)
            : $state['table']->find((string) $key);
        if (null === $var) {
            throw new \LogicException('ArrayIterator current key missing from backing array');
        }

        return $var;
    }

    public static function iteratorKey(ObjectEntry $object): int|string
    {
        $state = self::state($object);
        if (!self::iteratorValid($object)) {
            throw new \RuntimeException('Cannot fetch key() on invalid ArrayIterator position');
        }

        return self::iteratorKeys($object)[$state['pos']];
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::iteratorKeys($object));
    }

    public static function getArrayCopy(ObjectEntry $object): HashTable
    {
        return self::state($object)['table']->duplicate();
    }

    /** @return array<int|string, mixed> */
    public static function hashTableToExportedArray(HashTable $table): array
    {
        $out = [];
        foreach ($table->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
            $out[$key] = VmJson::export($valVar);
        }

        return $out;
    }

    /** @param array<int|string, mixed> $data */
    public static function exportedArrayToHashTable(array $data): HashTable
    {
        return VmJson::import($data)->toArray();
    }

    public static function restoreFromExported(
        Context $ctx,
        ObjectEntry $object,
        int $flags,
        array $storage,
        array $propList,
        mixed $iteratorClass
    ): void {
        $table = self::exportedArrayToHashTable($storage);
        $iterClass = \is_string($iteratorClass) && '' !== $iteratorClass ? $iteratorClass : null;
        self::init($object, $table, $flags, $iterClass, $propList);
    }
}
