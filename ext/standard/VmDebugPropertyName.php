<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Format Zend-mangled property hash keys for var_dump / print_r display.
 *
 * php-src: zend_print_property_info / php_var_dump (ext/standard/var.c) —
 * "\0*\0name" → protected, "\0Class\0name" → private.
 */
final class VmDebugPropertyName
{
    /**
     * var_dump(): [0], ["name"], ["name":protected], ["name":"Class":private]
     *
     * Integer keys from __debugInfo stay unquoted (php-src php_var_dump; #19783).
     *
     * @param int|string $key
     */
    public static function formatForVarDump($key): string
    {
        if (\is_int($key)) {
            return '['.$key.']';
        }
        $decoded = self::decode($key);
        if (null === $decoded) {
            return '["'.$key.'"]';
        }
        if ('protected' === $decoded['vis']) {
            return '["'.$decoded['name'].'":protected]';
        }

        return '["'.$decoded['name'].'":"'.$decoded['class'].'":private]';
    }

    /**
     * print_r(): [0], [name], [name:protected], [name:Class:private]
     *
     * @param int|string $key
     */
    public static function formatForPrintR($key): string
    {
        if (\is_int($key)) {
            return '['.$key.']';
        }
        $decoded = self::decode($key);
        if (null === $decoded) {
            return '['.$key.']';
        }
        if ('protected' === $decoded['vis']) {
            return '['.$decoded['name'].':protected]';
        }

        return '['.$decoded['name'].':'.$decoded['class'].':private]';
    }

    /**
     * @return null|array{vis: 'protected'|'private', name: string, class?: string}
     */
    private static function decode(string $key): ?array
    {
        if ('' === $key || "\0" !== $key[0]) {
            return null;
        }
        // "\0*\0name"
        if (\strlen($key) >= 3 && '*' === $key[1] && "\0" === $key[2]) {
            return [
                'vis' => 'protected',
                'name' => substr($key, 3),
            ];
        }
        // "\0Class\0name"
        $rest = substr($key, 1);
        $nul = strpos($rest, "\0");
        if (false === $nul) {
            return null;
        }

        return [
            'vis' => 'private',
            'class' => VmObjectDebugType::fromClassName(substr($rest, 0, $nul)),
            'name' => substr($rest, $nul + 1),
        ];
    }
}
