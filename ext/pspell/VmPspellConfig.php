<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PSpell\Config object + AspellConfig state (php-src ext/pspell; #22229).
 */
final class VmPspellConfig
{
    public const CLASS_LC = 'pspell\\config';

    public const CLASS_NAME = 'PSpell\\Config';

    /** @var array<int, array{native: \FFI\CData, freed: bool}> */
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
            throw new \ValueError('Invalid or uninitialized PSpell\\Config object');
        }

        return self::$state[$object->id]['native'];
    }

    public static function free(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            return;
        }
        VmPspellNative::deleteConfig(self::$state[$object->id]['native']);
        self::$state[$object->id]['freed'] = true;
    }
}
