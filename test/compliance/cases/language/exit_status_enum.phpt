--TEST--
Language: ExitStatus phantom absent under PROFILE=8.4; exit(0) ok (#28500, re-#28200 / #7294)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(enum_exists('ExitStatus', false));
echo "\n";
exit(0);
--EXPECT--
false
--EXPECT_EXIT--
0
