--TEST--
AOT: convert_cyr_string() Cyrillic charset conversion — pre-8.0 legacy (#4649, #21481)
--ENV--
PHP_COMPILER_PROFILE=7.4
--FILE--
<?php
echo bin2hex(convert_cyr_string("\xFE", 'w', 'd')), "\n";
echo bin2hex(convert_cyr_string("\xe0", 'k', 'w')), "\n";
--EXPECT--
ee
de
