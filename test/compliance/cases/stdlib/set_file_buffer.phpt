--TEST--
set_file_buffer() on memory stream — alias of stream_set_read_buffer() (issue #6107)
--FILE--
<?php
echo function_exists('set_file_buffer') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
$prev = set_file_buffer($fp, 8192);
echo is_int($prev) ? 'ok' : 'no', "\n";
fclose($fp);
try {
    set_file_buffer(42, 8192);
    echo "no\n";
} catch (TypeError $e) {
    echo "type\n";
}
--EXPECT--
1
ok
type
