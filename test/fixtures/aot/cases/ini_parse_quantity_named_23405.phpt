--TEST--
AOT: ini_parse_quantity() named shorthand: argument (#23405)
--FILE--
<?php
echo ini_parse_quantity('10k'), "\n";
echo ini_parse_quantity(shorthand: '10k'), "\n";
echo ini_parse_quantity(shorthand: '1G'), "\n";
--EXPECT--
10240
10240
1073741824
