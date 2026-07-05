--TEST--
stdlib stream_set_read_buffer()/stream_set_write_buffer() — null $size coerces to 0 (#16574, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
var_export(stream_set_read_buffer($fp, null));
echo "\n";
var_export(stream_set_write_buffer($fp, null));
fclose($fp);
?>
--EXPECT--
0
-1
