--TEST--
stdlib convert_cyr_string() — unknown charset warning (#4649)
--FILE--
<?php
$r = @convert_cyr_string('x', 'X', 'w');
echo bin2hex($r), "\n";
--EXPECT--
78
