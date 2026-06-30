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
 */
final class SplObjectStorageSerializeSupport
{
    public const CLASS_LC = 'splobjectstorage';

    public static function isSplObjectStorageClass(string $lcClass): bool
    {
        return self::CLASS_LC === $lcClass;
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
