<?php

declare(strict_types=1);

/**
 * VM process helpers — libc FFI when available (#5388, #7862).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

final class VmProcess
{
    /**
     * @return HashTable|false
     */
    public static function getrusage(int $who = 0)
    {
        $raw = false;
        if (VmGetrusageNative::available()) {
            $raw = VmGetrusageNative::getrusage($who);
        } elseif (\function_exists('getrusage')) {
            $raw = @\getrusage($who);
        }
        if (false === $raw) {
            return false;
        }

        $ht = new HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            $slot->int((int) $value);
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    /** proc_nice() — libc nice(3) via FFI (php-src basic_functions.c; #5181, #7862). */
    public static function proc_nice(int $priority): bool
    {
        return VmProcNiceNative::proc_nice($priority);
    }
}
