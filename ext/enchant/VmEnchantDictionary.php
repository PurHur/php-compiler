<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * EnchantDictionary object + native state (php-src ext/enchant; #6230).
 */
final class VmEnchantDictionary
{
    public const CLASS_LC = 'enchantdictionary';

    public const CLASS_NAME = 'EnchantDictionary';

    /** @var array<int, array{native: \FFI\CData, broker: ObjectEntry, freed: bool}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrap(\FFI\CData $native, ObjectEntry $broker, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'native' => $native,
            'broker' => $broker,
            'freed' => false,
        ];
        VmEnchantBroker::incrementDictCount($broker);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['freed'];
    }

    public static function native(ObjectEntry $object): \FFI\CData
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            throw new \ValueError('Invalid or uninitialized EnchantDictionary object');
        }

        return self::$state[$object->id]['native'];
    }

    public static function broker(ObjectEntry $object): ObjectEntry
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            throw new \ValueError('Invalid or uninitialized EnchantDictionary object');
        }

        return self::$state[$object->id]['broker'];
    }

    public static function free(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            throw new \ValueError('Invalid or uninitialized EnchantDictionary object');
        }
        $broker = self::$state[$object->id]['broker'];
        if (VmEnchantBroker::isLive($broker)) {
            VmEnchantNative::freeDict(
                VmEnchantBroker::native($broker),
                self::$state[$object->id]['native']
            );
            VmEnchantBroker::decrementDictCount($broker);
        }
        self::$state[$object->id]['freed'] = true;

        return true;
    }
}
