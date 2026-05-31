--TEST--
stream_set_write_buffer() / stream_set_read_buffer() on memory stream (issue #3755)
--FILE--
<?php
echo function_exists('stream_set_write_buffer') ? '1' : '0', "\n";
echo function_exists('stream_set_read_buffer') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'w+');
$prev = stream_set_write_buffer($fp, 0);
echo false !== $prev ? 'write' : 'no', "\n";
$prev = stream_set_read_buffer($fp, 0);
echo false !== $prev ? 'read' : 'no', "\n";
fwrite($fp, 'x');
fclose($fp);
try {
    stream_set_write_buffer(42, 0);
    echo "no\n";
} catch (TypeError $e) {
    echo "type\n";
}
--EXPECT--
1
1
write
read
type
