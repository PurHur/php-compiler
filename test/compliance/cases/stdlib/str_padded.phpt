--TEST--
stdlib str_padded() — UTF-8 codepoint padding (PHP 8.4, ext/standard/string.c, #7044)
--FILE--
<?php
echo str_padded('hi', 5), "\n";
echo str_padded('hi', 5, '0', 0), "\n";
echo str_padded('hi', 6, '-', 2), "\n";
echo str_padded('long', 3), "\n";
echo str_padded('日', 3), "\n";
echo str_padded('日', 4, '本'), "\n";
--EXPECT--
hi   
000hi
--hi--
long
日  
日本本本
