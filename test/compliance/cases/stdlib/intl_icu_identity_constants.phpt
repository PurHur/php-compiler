--TEST--
stdlib INTL_ICU_VERSION / INTL_ICU_DATA_VERSION / INTL_MAX_LOCALE_LEN (#24082, php-src ext/intl/php_intl.c)
--SKIPIF--
<?php
if (!extension_loaded('intl')) {
    echo 'skip intl extension required';
}
--FILE--
<?php
declare(strict_types=1);

echo (int) defined('INTL_ICU_VERSION'), "\n";
echo (int) defined('INTL_ICU_DATA_VERSION'), "\n";
echo (int) defined('INTL_MAX_LOCALE_LEN'), "\n";
echo INTL_MAX_LOCALE_LEN, "\n";
echo (int) (is_string(INTL_ICU_VERSION) && preg_match('/^\d+\.\d+/', INTL_ICU_VERSION) === 1), "\n";
echo (int) (is_string(INTL_ICU_DATA_VERSION) && preg_match('/^\d+\.\d+/', INTL_ICU_DATA_VERSION) === 1), "\n";
$intl = get_defined_constants(true)['intl'] ?? [];
echo (int) array_key_exists('INTL_ICU_VERSION', $intl), "\n";
echo (int) array_key_exists('INTL_ICU_DATA_VERSION', $intl), "\n";
echo (int) array_key_exists('INTL_MAX_LOCALE_LEN', $intl), "\n";
--EXPECT--
1
1
1
156
1
1
1
1
1
