--TEST--
AOT: convert_cyr_string() Cyrillic charset conversion (#4649)
--FILE--
<?php
echo bin2hex(convert_cyr_string("\xFE", 'w', 'd')), "\n";
echo bin2hex(convert_cyr_string("\xe0", 'k', 'w')), "\n";
--EXPECT--
ee
de
