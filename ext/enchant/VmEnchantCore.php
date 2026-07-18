<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared enchant_* semantics (php-src ext/enchant/enchant.c; #6230).
 */
final class VmEnchantCore
{
    /**
     * @return Variable|false EnchantBroker object variable, or false on failure
     */
    public static function brokerInit(Context $ctx): Variable|false
    {
        if (!VmEnchantNative::available()) {
            return false;
        }
        $native = VmEnchantNative::brokerInit();
        if (null === $native) {
            return false;
        }

        return VmEnchantBroker::wrap($native, $ctx);
    }

    /**
     * @return Variable|false EnchantDictionary object variable, or false on failure
     */
    public static function requestDict(ObjectEntry $broker, string $tag, Context $ctx): Variable|false
    {
        $native = VmEnchantNative::requestDict(VmEnchantBroker::native($broker), $tag);
        if (null === $native) {
            return false;
        }

        return VmEnchantDictionary::wrap($native, $broker, $ctx);
    }

    public static function dictExists(ObjectEntry $broker, string $tag): bool
    {
        return VmEnchantNative::dictExists(VmEnchantBroker::native($broker), $tag);
    }

    /** PHP bool: true when correctly spelled (php-src RETURN_BOOL(!enchant_dict_check(...))). */
    public static function dictCheck(ObjectEntry $dict, string $word): bool
    {
        return 0 === VmEnchantNative::dictCheckRaw(VmEnchantDictionary::native($dict), $word);
    }

    public static function dictSuggest(ObjectEntry $dict, string $word): HashTable
    {
        $ht = new HashTable();
        foreach (VmEnchantNative::dictSuggest(VmEnchantDictionary::native($dict), $word) as $i => $sugg) {
            $slot = new Variable();
            $slot->string($sugg);
            $ht->add((string) $i, $slot);
        }

        return $ht;
    }
}
