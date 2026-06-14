<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * get_headers() formatting helpers (php-src ext/standard/head.c — php_get_headers, #3309).
 */
final class VmHttpHeaders
{
    /**
     * @param list<string> $headers raw header lines including status line
     *
     * @return list<string>|array<int|string, string|list<string>>
     */
    public static function format(array $headers, bool $associative): array
    {
        if (!$associative) {
            return $headers;
        }

        $out = [];
        foreach ($headers as $i => $line) {
            if (0 === $i) {
                $out[0] = $line;

                continue;
            }

            $colon = \strpos($line, ':');
            if (false === $colon) {
                $out[] = $line;

                continue;
            }

            $name = \trim(\substr($line, 0, $colon));
            $value = \trim(\substr($line, $colon + 1));
            if ('' === $name) {
                continue;
            }

            if (!\array_key_exists($name, $out)) {
                $out[$name] = $value;

                continue;
            }

            $existing = $out[$name];
            if (\is_array($existing)) {
                $existing[] = $value;
                $out[$name] = $existing;
            } else {
                $out[$name] = [(string) $existing, $value];
            }
        }

        return $out;
    }

    /**
     * @param list<string>|array<int|string, string|list<string>> $formatted
     */
    public static function toHashTable(array $formatted, bool $associative): HashTable
    {
        if (!$associative) {
            /** @var list<string> $formatted */
            return VmFs::stringListToArray($formatted);
        }

        $ht = new HashTable();
        foreach ($formatted as $key => $value) {
            $var = new Variable();
            if (\is_array($value)) {
                $var->array(VmFs::stringListToArray($value));
            } else {
                $var->string((string) $value);
            }
            if (\is_int($key)) {
                $ht->updateIndex($key, $var);
            } else {
                $ht->update($key, $var);
            }
        }

        return $ht;
    }
}
