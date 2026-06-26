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
    public const FLAG_STD_PROP_LIST = 1;

    public const FLAG_ARRAY_AS_PROPS = 2;

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

    /** php-src spl_array_getIteratorClass — default ArrayIterator (#10639). */
    public static function getIteratorClass(ObjectEntry $object): string
    {
        $class = self::state($object)['iteratorClass'];

        return null !== $class && '' !== $class ? $class : 'ArrayIterator';
    }

    /** php-src spl_array_setIteratorClass (#10639). */
    public static function setIteratorClass(ObjectEntry $object, string $iteratorClass): void
    {
        self::$store[$object->id]['iteratorClass'] = $iteratorClass;
    }

    public static function getFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        self::$store[$object->id]['flags'] = $flags;
    }

    public static function isArrayObject(ObjectEntry $object): bool
    {
        return ArrayObjectBuiltin::CLASS_LC === strtolower(ltrim($object->class->name, '\\'));
    }

    /** php-src SPL_ARRAY_AS_PROPS — backing array keys as object properties (spl_array.c). */
    public static function hasArrayAsProps(ObjectEntry $object): bool
    {
        return self::hasState($object)
            && 0 !== (self::getFlags($object) & self::FLAG_ARRAY_AS_PROPS);
    }

    public static function createIterator(Context $ctx, ObjectEntry $object): Variable
    {
        $className = self::getIteratorClass($object);
        $lc = strtolower($className);
        $class = $ctx->classes[$lc] ?? null;
        if (null === $class) {
            throw new \LogicException("Iterator class '{$className}' is not registered");
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $table = self::getArrayCopy($object);
        if (ArrayIteratorBuiltin::CLASS_LC === $lc) {
            ArrayIteratorBuiltin::init($entry, $table);
        } else {
            SplArrayStorage::init($entry, $table, 0, null, []);
        }
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function offsetGet(ObjectEntry $object, Variable $offset): Variable
    {
        $found = self::findOffset(self::state($object)['table'], $offset);
        if (null === $found) {
            $var = new Variable(Variable::TYPE_NULL);
            $var->null();

            return $var;
        }
        $resolved = $found->resolveIndirect();
        $out = new Variable($resolved->type);
        $out->copyFrom($resolved);

        return $out;
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        $table = self::state($object)['table'];
        $resolved = $value->resolveIndirect();
        [$keyVar, $isInt] = self::offsetKeyVar($offset);
        if ($isInt) {
            $idx = $keyVar->toInt();
            if (null !== $table->findIndex($idx)) {
                $table->updateIndex($idx, $resolved);
            } else {
                $table->addIndex($idx, $resolved);
            }

            return;
        }
        $key = $keyVar->toString();
        if (null !== $table->find($key)) {
            $table->update($key, $resolved);
        } else {
            $table->add($key, $resolved);
        }
    }

    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        return null !== self::findOffset(self::state($object)['table'], $offset);
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        [$keyVar] = self::offsetKeyVar($offset);
        self::state($object)['table']->offsetUnset($keyVar);
    }

    /** php-src spl_array_method_append — push with next numeric index. */
    public static function append(ObjectEntry $object, Variable $value): void
    {
        $resolved = $value->resolveIndirect();
        $stored = new Variable($resolved->type);
        $stored->copyFrom($resolved);
        self::state($object)['table']->append($stored);
    }

    private static function findOffset(\PHPCompiler\VM\HashTable $table, Variable $offset): ?Variable
    {
        [$keyVar, $isInt] = self::offsetKeyVar($offset);

        return $isInt
            ? $table->findIndex($keyVar->toInt())
            : $table->find($keyVar->toString());
    }

    /** @return array{0: Variable, 1: bool} */
    private static function offsetKeyVar(Variable $offset): array
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $key = new Variable(Variable::TYPE_INTEGER);
            $key->int($resolved->toInt());

            return [$key, true];
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($resolved->toString());

            return [$key, false];
        }
        if (Variable::TYPE_DOUBLE === $resolved->type) {
            $key = new Variable(Variable::TYPE_INTEGER);
            $key->int((int) $resolved->toDouble());

            return [$key, true];
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string('');

            return [$key, false];
        }

        throw new \TypeError('Array access offset must be of type int or string');
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
