--TEST--
stdlib preg_replace_callback() limit and count by-ref (issue #4442)
--FILE--
<?php
$count = 0;
$out = preg_replace_callback('/a/', function ($m) { return 'x'; }, 'aa', 1, $count);
var_dump($out, $count);
?>
--EXPECT--
string(2) "xa"
int(1)
