--TEST--
stdlib convert_uudecode() invalid input
--FILE--
<?php
$result = convert_uudecode('!!!');
echo ($result === false) ? "false\n" : "not-false\n";
--EXPECT--
false
--EXPECT_EXIT--
0
