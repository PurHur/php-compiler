--TEST--
stdlib ICU U_* error globals defined with loaded ext/intl (#23998, php-src ext/intl/php_intl.c)
--SKIPIF--
<?php
if (!extension_loaded('intl')) {
    echo 'skip intl extension required';
}
--FILE--
<?php
declare(strict_types=1);

echo (int) defined('U_ZERO_ERROR'), "\n";
echo (int) defined('U_ILLEGAL_ARGUMENT_ERROR'), "\n";
echo U_ZERO_ERROR, "\n";
echo U_ILLEGAL_ARGUMENT_ERROR, "\n";
echo intl_error_name(U_ZERO_ERROR), "\n";
$intl = get_defined_constants(true)['intl'] ?? [];
$u = array_values(array_filter(array_keys($intl), static fn ($k) => str_starts_with($k, 'U_')));
sort($u);
echo 'U_count=', count($u), "\n";
echo 'has_U_ZERO=', (int) in_array('U_ZERO_ERROR', $u, true), "\n";
echo 'has_U_ILLEGAL=', (int) in_array('U_ILLEGAL_ARGUMENT_ERROR', $u, true), "\n";
--EXPECT--
1
1
0
1
U_ZERO_ERROR
U_count=141
has_U_ZERO=1
has_U_ILLEGAL=1
