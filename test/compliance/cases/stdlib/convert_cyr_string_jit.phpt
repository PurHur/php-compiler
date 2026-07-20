--TEST--
stdlib convert_cyr_string() JIT/AOT — pre-8.0 legacy (#4649, #21481)
--ENV--
PHP_COMPILER_PROFILE=7.4
--FILE--
<?php
echo function_exists('convert_cyr_string') ? "yes\n" : "no\n";
echo bin2hex(convert_cyr_string("\xFE", 'w', 'd')), "\n";
echo bin2hex(convert_cyr_string("\xe0", 'k', 'w')), "\n";
echo convert_cyr_string('hello', 'k', 'w'), "\n";
--EXPECT--
yes
ee
de
hello
