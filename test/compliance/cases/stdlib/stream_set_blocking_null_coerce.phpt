--TEST--
stdlib stream_set_blocking() — null $mode coerces under non-strict caller (#16524, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
var_export(stream_set_blocking($fp, null));
?>
--EXPECT--
true
