--TEST--
stdlib convert_cyr_string() — unknown charset warning (#4649, #21481)
--ENV--
PHP_COMPILER_PROFILE=7.4
--FILE--
<?php
$r = @convert_cyr_string('x', 'X', 'w');
echo bin2hex($r), "\n";
--EXPECT--
78
