--TEST--
stdlib readline() registered; function_exists true (issue #3776)
--FILE--
<?php
echo function_exists('readline') ? "exists\n" : "missing\n";
$r = readline();
echo ($r === false || is_string($r)) ? "ok\n" : "bad\n";
?>
--EXPECT--
exists
ok
