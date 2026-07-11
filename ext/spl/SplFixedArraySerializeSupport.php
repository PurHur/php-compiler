<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Zend serialize wire for SplFixedArray (php-src ext/spl/spl_fixedarray.c; #13179).
 */
final class SplFixedArraySerializeSupport
{
    public const CLASS_LC = 'splfixedarray';

    public static function isSplFixedArrayClass(string $lcClass): bool
    {
        return self::CLASS_LC === $lcClass;
    }

    public static function encodeZendSerializeWire(ObjectEntry $entry): string
    {
        return VmSerialize::encodeIntegerKeyedPropertyBag(
            $entry->class->name,
            self::exportElements($entry)
        );
    }

    /**
     * @return array<int, mixed>
     */
    public static function exportElements(ObjectEntry $entry): array
    {
        if (!SplFixedArrayBuiltin::hasState($entry)) {
            return [];
        }
        $size = SplFixedArrayBuiltin::getSize($entry);
        $exported = [];
        for ($i = 0; $i < $size; ++$i) {
            $slot = SplFixedArrayBuiltin::offsetGet($entry, self::intVar($i));
            $exported[$i] = VmJson::export($slot->resolveIndirect());
        }

        return $exported;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function restoreFromZendSerialize(
        Context $ctx,
        array $data
    ): ?ObjectEntry {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return null;
        }
        $indexed = [];
        $maxIndex = -1;
        foreach ($data as $key => $raw) {
            if (!\is_int($key) && (!\is_string($key) || !ctype_digit((string) $key))) {
                return null;
            }
            $index = (int) $key;
            if ($index < 0) {
                return null;
            }
            if ($index > $maxIndex) {
                $maxIndex = $index;
            }
            $indexed[$index] = $raw;
        }
        if ($maxIndex < 0) {
            $indexed = [];
        }
        $entry = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $entry->constructed = true;
        SplFixedArrayBuiltin::restoreExportedState($entry, $indexed);

        return $entry;
    }

    private static function intVar(int $value): Variable
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }
}
