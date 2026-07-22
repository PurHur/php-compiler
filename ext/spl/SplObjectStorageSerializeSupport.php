<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\ext\standard\VmSerializeRefState;
use PHPCompiler\ext\standard\VmUnserializeFormat;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Zend serialize wire for SplObjectStorage (php-src ext/spl/spl_object_storage.c; #14164).
 * __serialize/__unserialize bag shape (#22268) — php-src ext/spl/spl_observer.c.
 */
final class SplObjectStorageSerializeSupport
{
    public const CLASS_LC = 'splobjectstorage';

    public static function isSplObjectStorageClass(string $lcClass): bool
    {
        return self::CLASS_LC === $lcClass;
    }

    /**
     * php-src SplObjectStorage::__serialize — [0 => flat object/info pairs, 1 => members].
     */
    public static function exportSerializeBag(ObjectEntry $storage): Variable
    {
        $pairs = [];
        foreach (SplObjectStorageBuiltin::exportSerializeEntries($storage) as [$object, $info]) {
            $objCopy = new Variable();
            $objCopy->copyFrom($object->resolveIndirect());
            $pairs[] = $objCopy;
            $infoCopy = new Variable();
            $infoCopy->copyFrom($info->resolveIndirect());
            $pairs[] = $infoCopy;
        }

        $storageVar = new Variable(Variable::TYPE_ARRAY);
        $storageVar->newArray();
        if ([] !== $pairs) {
            $storageVar->toArray()->assignPackedList($pairs);
        }

        $membersVar = new Variable(Variable::TYPE_ARRAY);
        $membersVar->newArray();

        $result = new Variable(Variable::TYPE_ARRAY);
        $result->newArray();
        $result->toArray()->assignPackedList([$storageVar, $membersVar]);

        return $result;
    }

    /**
     * php-src SplObjectStorage::__unserialize — attach pairs; does not clear existing storage.
     */
    public static function restoreFromSerializeBag(ObjectEntry $storage, Variable $data): void
    {
        $ht = $data->toArray();
        $storageSlot = null;
        $membersSlot = null;
        foreach ($ht->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                continue;
            }
            $idx = $key->toInt();
            if (0 === $idx) {
                $storageSlot = $valueVar->resolveIndirect();
            } elseif (1 === $idx) {
                $membersSlot = $valueVar->resolveIndirect();
            }
        }
        if (
            null === $storageSlot || null === $membersSlot
            || Variable::TYPE_ARRAY !== $storageSlot->type
            || Variable::TYPE_ARRAY !== $membersSlot->type
        ) {
            throw new \UnexpectedValueException('Incomplete or ill-typed serialization data');
        }

        $slots = [];
        foreach ($storageSlot->toArray()->iterateKeyed(false) as [, $slot]) {
            $slots[] = $slot->resolveIndirect();
        }
        if (0 !== (\count($slots) % 2)) {
            throw new \UnexpectedValueException('Odd number of elements');
        }
        for ($i = 0; isset($slots[$i + 1]); $i += 2) {
            $object = $slots[$i];
            if (Variable::TYPE_OBJECT !== $object->type) {
                throw new \UnexpectedValueException('Non-object key');
            }
            SplObjectStorageBuiltin::attach($storage, $object, $slots[$i + 1]);
        }
        // Members bag (index 1) is accepted for Zend shape parity; dynamic props unused on internal SOS.
        unset($membersSlot);
    }

    public static function encodeZendSerializeWire(
        Context $ctx,
        ObjectEntry $entry,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        $entriesBody = '';
        $index = 0;
        foreach (SplObjectStorageBuiltin::exportSerializeEntries($entry) as [$object, $info]) {
            $entriesBody .= 'i:'.$index.';';
            $entriesBody .= VmSerialize::encodeVariableWire($ctx, $object, $state, $frame);
            ++$index;
            $entriesBody .= 'i:'.$index.';';
            $entriesBody .= VmSerialize::encodeVariableWire($ctx, $info, $state, $frame);
            ++$index;
        }
        $entriesWire = 'a:'.$index.':{'.$entriesBody.'}';

        $body = 'i:0;'.$entriesWire.'i:1;a:0:{}';
        $className = $entry->class->name;
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":2:{'.$body.'}';
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function restoreFromZendSerialize(Context $ctx, array $data): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return null;
        }
        if (!isset($data[0]) || !\is_array($data[0])) {
            return null;
        }
        $entry = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $entry->constructed = true;
        SplObjectStorageBuiltin::init($entry);
        $pairs = $data[0];
        for ($i = 0; isset($pairs[$i], $pairs[$i + 1]); $i += 2) {
            $object = self::pairValueToVariable($ctx, $pairs[$i]);
            $info = self::pairValueToVariable($ctx, $pairs[$i + 1]);
            if (null === $object || Variable::TYPE_OBJECT !== $object->type) {
                continue;
            }
            SplObjectStorageBuiltin::attach($entry, $object, $info);
        }

        return $entry;
    }

    public static function restoreFromWire(
        Context $ctx,
        string $payload,
        ?array $options = null,
        ?Frame $frame = null
    ): ?ObjectEntry {
        $header = VmSerialize::parseObjectWireHeader($payload);
        if (null === $header) {
            return null;
        }
        [, , $inner] = $header;
        if (!\preg_match('/i:0;(a:\d+:\{.*\})(?=i:1;)/s', $inner, $matches)) {
            return null;
        }
        $entriesVar = VmUnserializeFormat::decodeToVariableWithContext($ctx, $matches[1], $options, $frame);
        if (false === $entriesVar || Variable::TYPE_ARRAY !== $entriesVar->type) {
            return null;
        }
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return null;
        }
        $entry = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $entry->constructed = true;
        SplObjectStorageBuiltin::init($entry);
        $slots = [];
        foreach ($entriesVar->toArray()->iterateKeyed(false) as [, $slot]) {
            $slots[] = $slot;
        }
        for ($i = 0; isset($slots[$i + 1]); $i += 2) {
            $object = $slots[$i]->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $object->type) {
                continue;
            }
            SplObjectStorageBuiltin::attach($entry, $object, $slots[$i + 1]);
        }

        return $entry;
    }

    private static function pairValueToVariable(Context $ctx, mixed $raw): ?Variable
    {
        if ($raw instanceof Variable) {
            return $raw;
        }
        if ($raw instanceof VmUnserializeRootObject) {
            return $raw->objectVar;
        }
        if (!\is_array($raw) && !\is_scalar($raw) && null !== $raw) {
            return null;
        }

        return VmJson::import($raw);
    }
}
