<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** DNS helpers for stdlib builtins (issue #3707). */
final class VmDns
{
    /**
     * @return HashTable|false
     */
    public static function gethostbynamel(string $hostname)
    {
        $ips = @\gethostbynamel($hostname);
        if (false === $ips || !\is_array($ips) || [] === $ips) {
            return false;
        }
        $ht = new HashTable();
        foreach ($ips as $index => $ip) {
            if (!\is_string($ip) || '' === $ip) {
                continue;
            }
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($ip);
            $ht->add((string) $index, $var);
        }
        if (0 === $ht->getNumElements()) {
            return false;
        }

        return $ht;
    }
}
