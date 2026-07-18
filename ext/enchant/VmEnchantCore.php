<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared enchant_* semantics (php-src ext/enchant/enchant.c; #6230 / #20613).
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

    /**
     * @return Variable|false EnchantDictionary object variable, or false on failure
     */
    public static function requestPwlDict(ObjectEntry $broker, string $filename, Context $ctx): Variable|false
    {
        // php-src php_check_open_basedir — empty open_basedir always allows.
        $basedir = \ini_get('open_basedir');
        if (false !== $basedir && '' !== $basedir) {
            // Host PHP open_basedir check via realpath prefix when configured.
            $real = @\realpath($filename);
            if (false === $real) {
                return false;
            }
            $allowed = false;
            foreach (explode(PATH_SEPARATOR, $basedir) as $root) {
                $root = rtrim($root, DIRECTORY_SEPARATOR);
                if ('' === $root) {
                    continue;
                }
                if ($real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return false;
            }
        }

        $native = VmEnchantNative::requestPwlDict(VmEnchantBroker::native($broker), $filename);
        if (null === $native) {
            return false;
        }

        return VmEnchantDictionary::wrap($native, $broker, $ctx);
    }

    public static function dictExists(ObjectEntry $broker, string $tag): bool
    {
        return VmEnchantNative::dictExists(VmEnchantBroker::native($broker), $tag);
    }

    public static function setOrdering(ObjectEntry $broker, string $tag, string $ordering): bool
    {
        VmEnchantNative::setOrdering(VmEnchantBroker::native($broker), $tag, $ordering);

        return true;
    }

    public static function brokerGetError(ObjectEntry $broker): string|false
    {
        $msg = VmEnchantNative::brokerGetError(VmEnchantBroker::native($broker));

        return null === $msg ? false : $msg;
    }

    public static function brokerDescribe(ObjectEntry $broker): HashTable
    {
        $ht = new HashTable();
        foreach (VmEnchantNative::brokerDescribe(VmEnchantBroker::native($broker)) as $i => $row) {
            $slot = new Variable();
            $slot->array(self::assocStringTable($row));
            $ht->add((string) $i, $slot);
        }

        return $ht;
    }

    public static function brokerListDicts(ObjectEntry $broker): HashTable
    {
        $ht = new HashTable();
        foreach (VmEnchantNative::brokerListDicts(VmEnchantBroker::native($broker)) as $i => $row) {
            $slot = new Variable();
            $slot->array(self::assocStringTable($row));
            $ht->add((string) $i, $slot);
        }

        return $ht;
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

    /**
     * @param Variable|null $suggestionsArg by-ref suggestions slot (optional)
     */
    public static function dictQuickCheck(ObjectEntry $dict, string $word, ?Variable $suggestionsArg): bool
    {
        $native = VmEnchantDictionary::native($dict);
        if (0 === VmEnchantNative::dictCheckRaw($native, $word)) {
            if (null !== $suggestionsArg) {
                $suggestionsArg->array(new HashTable());
            }

            return true;
        }
        if (null === $suggestionsArg) {
            return false;
        }
        $ht = new HashTable();
        foreach (VmEnchantNative::dictSuggest($native, $word) as $i => $sugg) {
            $slot = new Variable();
            $slot->string($sugg);
            $ht->add((string) $i, $slot);
        }
        $suggestionsArg->array($ht);

        return false;
    }

    public static function dictAdd(ObjectEntry $dict, string $word): void
    {
        VmEnchantNative::dictAdd(VmEnchantDictionary::native($dict), $word);
    }

    public static function dictRemove(ObjectEntry $dict, string $word): void
    {
        VmEnchantNative::dictRemove(VmEnchantDictionary::native($dict), $word);
    }

    public static function dictAddToSession(ObjectEntry $dict, string $word): void
    {
        VmEnchantNative::dictAddToSession(VmEnchantDictionary::native($dict), $word);
    }

    public static function dictRemoveFromSession(ObjectEntry $dict, string $word): void
    {
        VmEnchantNative::dictRemoveFromSession(VmEnchantDictionary::native($dict), $word);
    }

    public static function dictIsAdded(ObjectEntry $dict, string $word): bool
    {
        return VmEnchantNative::dictIsAdded(VmEnchantDictionary::native($dict), $word);
    }

    public static function dictStoreReplacement(ObjectEntry $dict, string $misspelled, string $correct): void
    {
        VmEnchantNative::dictStoreReplacement(VmEnchantDictionary::native($dict), $misspelled, $correct);
    }

    public static function dictGetError(ObjectEntry $dict): string|false
    {
        $msg = VmEnchantNative::dictGetError(VmEnchantDictionary::native($dict));

        return null === $msg ? false : $msg;
    }

    public static function dictDescribe(ObjectEntry $dict): HashTable
    {
        $row = VmEnchantNative::dictDescribe(VmEnchantDictionary::native($dict));
        if (null === $row) {
            return new HashTable();
        }

        return self::assocStringTable($row);
    }

    /**
     * @param array<string, string> $row
     */
    private static function assocStringTable(array $row): HashTable
    {
        $ht = new HashTable();
        foreach ($row as $key => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }
}
