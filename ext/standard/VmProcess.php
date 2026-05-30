<?php

declare(strict_types=1);

/**
 * VM process helpers (host libc via Zend PHP for parity).
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
        $raw = @\getrusage($who);
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
}
