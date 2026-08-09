--TEST--
stdlib mb_str_pad() — multibyte padding on PROFILE=8.4 (php-src ext/mbstring/mbstring.c, #6081, #22373)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('mb_str_pad') ? "exists\n" : "missing\n";
echo mb_str_pad('hi', 5), "\n";
echo mb_str_pad('hi', 5, '0', 0), "\n";
echo mb_str_pad('hi', 6, '-', 2), "\n";
echo mb_str_pad('long', 3), "\n";
echo mb_str_pad('日', 3), "\n";
echo mb_str_pad('日', 4, '本'), "\n";
echo mb_str_pad('hi', 5, ' ', 1, 'ASCII'), "\n";
try {
    mb_str_pad('hi', 5, '');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
exists
hi   
000hi
--hi--
long
日  
日本本本
hi   
mb_str_pad(): Argument #3 ($pad_string) must not be empty
