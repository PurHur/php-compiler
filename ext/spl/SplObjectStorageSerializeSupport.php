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
     * php-src zim_SplObjectStorage_serialize — x:/m: legacy wire (#31627).
     */
    public static function encodeLegacySerializeWire(
        Context $ctx,
        ObjectEntry $entry,
        ?Frame $frame = null
    ): string {
        // VmSerializeRefState lives in VmSerialize.php — force autoload before `new`.
        \class_exists(VmSerialize::class);
        $state = new VmSerializeRefState();
        $entries = SplObjectStorageBuiltin::exportSerializeEntries($entry);
        $buf = 'x:'.VmSerialize::serializeExported(\count($entries));
        foreach ($entries as [$object, $info]) {
            $buf .= VmSerialize::encodeVariableWire($ctx, $object, $state, $frame);
            $buf .= ',';
            $buf .= VmSerialize::encodeVariableWire($ctx, $info, $state, $frame);
            $buf .= ';';
        }
        $buf .= 'm:'.VmSerialize::serializeExported([]);

        return $buf;
    }

    /**
     * php-src zim_SplObjectStorage_unserialize — mutate $this from x:/m: wire (#31627).
     */
    public static function restoreFromLegacySerializeWire(
        Context $ctx,
        ObjectEntry $object,
        string $buf,
        ?Frame $frame = null
    ): void {
        $bufLen = \strlen($buf);
        if (0 === $bufLen) {
            return;
        }
        $p = 0;
        if ($buf[$p] !== 'x') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;
        if ($p >= $bufLen || $buf[$p] !== ':') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;

        $countDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx, $frame);
        if (false === $countDecoded || Variable::TYPE_INTEGER !== $countDecoded[0]->type) {
            throw self::legacyUnserializeErrorAt(
                VmUnserializeFormat::lastErrorOffset() ?? $p,
                $bufLen
            );
        }
        [$countVar, $p] = $countDecoded;
        // php-src: --p then require ';' at each element / before members.
        if ($p < 1 || $buf[$p - 1] !== ';') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        --$p;
        $count = $countVar->toInt();
        if ($count < 0) {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }

        SplObjectStorageBuiltin::init($object);
        while ($count-- > 0) {
            if ($p >= $bufLen || $buf[$p] !== ';') {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            ++$p;
            if ($p >= $bufLen) {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            $type = $buf[$p];
            if ('O' !== $type && 'C' !== $type && 'r' !== $type) {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            $entryDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx, $frame);
            if (false === $entryDecoded) {
                throw self::legacyUnserializeErrorAt(
                    VmUnserializeFormat::lastErrorOffset() ?? $p,
                    $bufLen
                );
            }
            [$entryVar, $p] = $entryDecoded;
            $infoVar = null;
            if ($p < $bufLen && $buf[$p] === ',') {
                ++$p;
                $infoDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx, $frame);
                if (false === $infoDecoded) {
                    throw self::legacyUnserializeErrorAt(
                        VmUnserializeFormat::lastErrorOffset() ?? $p,
                        $bufLen
                    );
                }
                [$infoVar, $p] = $infoDecoded;
            }
            if (Variable::TYPE_OBJECT !== $entryVar->type) {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            SplObjectStorageBuiltin::attach($object, $entryVar, $infoVar);
        }

        if ($p >= $bufLen || $buf[$p] !== ';') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;
        if ($p >= $bufLen || $buf[$p] !== 'm') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;
        if ($p >= $bufLen || $buf[$p] !== ':') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;

        $membersDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx, $frame);
        if (false === $membersDecoded || Variable::TYPE_ARRAY !== $membersDecoded[0]->type) {
            throw self::legacyUnserializeErrorAt(
                VmUnserializeFormat::lastErrorOffset() ?? $p,
                $bufLen
            );
        }
        // Members bag accepted for Zend shape parity; dynamic props unused on internal SOS.
    }

    private static function legacyUnserializeErrorAt(int $offset, int $bufLen): \UnexpectedValueException
    {
        return new \UnexpectedValueException(
            \sprintf('Error at offset %d of %d bytes', $offset, $bufLen)
        );
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
