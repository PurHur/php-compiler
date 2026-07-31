--TEST--
stdlib ULOC_ACTUAL_LOCALE / ULOC_VALID_LOCALE globals (#24097, php-src ext/intl/locale)
--SKIPIF--
<?php
if (!extension_loaded('intl')) {
    echo 'skip intl extension required';
}
--FILE--
<?php
declare(strict_types=1);

echo (int) defined('ULOC_ACTUAL_LOCALE'), "\n";
echo (int) defined('ULOC_VALID_LOCALE'), "\n";
echo ULOC_ACTUAL_LOCALE, "\n";
echo ULOC_VALID_LOCALE, "\n";
echo (int) (ULOC_ACTUAL_LOCALE === Locale::ACTUAL_LOCALE), "\n";
echo (int) (ULOC_VALID_LOCALE === Locale::VALID_LOCALE), "\n";
$intl = get_defined_constants(true)['intl'] ?? [];
echo (int) array_key_exists('ULOC_ACTUAL_LOCALE', $intl), "\n";
echo (int) array_key_exists('ULOC_VALID_LOCALE', $intl), "\n";
--EXPECT--
1
1
0
1
1
1
1
1
