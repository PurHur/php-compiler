--TEST--
stdlib convert_cyr_string() JIT/AOT (#4649)
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
