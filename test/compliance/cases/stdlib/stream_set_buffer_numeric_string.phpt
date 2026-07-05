--TEST--
stdlib stream_set_read_buffer()/stream_set_write_buffer() numeric-string size coercion (#16576, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://memory', 'w+');
$write = stream_set_write_buffer($fp, '0');
$read = stream_set_read_buffer($fp, '0');
echo is_int($write) ? 'write' : 'no_write', "\n";
echo is_int($read) ? 'read' : 'no_read', "\n";
fclose($fp);
--EXPECT--
write
read
