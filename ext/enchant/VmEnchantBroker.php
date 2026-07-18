<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * EnchantBroker object + native state (php-src ext/enchant; #6230).
 */
final class VmEnchantBroker
{
    public const CLASS_LC = 'enchantbroker';

    public const CLASS_NAME = 'EnchantBroker';

    /** @var array<int, array{native: \FFI\CData, freed: bool, dict_count: int}> */
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

    public static function wrap(\FFI\CData $native, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'native' => $native,
            'freed' => false,
            'dict_count' => 0,
        ];
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
            throw new \ValueError('Invalid or uninitialized EnchantBroker object');
        }

        return self::$state[$object->id]['native'];
    }

    public static function dictCount(ObjectEntry $object): int
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            throw new \ValueError('Invalid or uninitialized EnchantBroker object');
        }

        return self::$state[$object->id]['dict_count'];
    }

    public static function incrementDictCount(ObjectEntry $object): void
    {
        self::$state[$object->id]['dict_count']++;
    }

    public static function decrementDictCount(ObjectEntry $object): void
    {
        if (self::$state[$object->id]['dict_count'] > 0) {
            self::$state[$object->id]['dict_count']--;
        }
    }

    public static function free(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            throw new \ValueError('Invalid or uninitialized EnchantBroker object');
        }
        if (self::$state[$object->id]['dict_count'] > 0) {
            throw new \Error('Cannot free EnchantBroker object with open EnchantDictionary objects');
        }
        VmEnchantNative::brokerFree(self::$state[$object->id]['native']);
        self::$state[$object->id]['freed'] = true;

        return true;
    }
}
