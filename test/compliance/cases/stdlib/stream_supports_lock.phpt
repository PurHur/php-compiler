--TEST--
stream_supports_lock() on php://memory and temp file (issue #6039, php-src ext/standard/streams.c)
--FILE--
<?php
echo function_exists('stream_supports_lock') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'test');
rewind($fp);
echo stream_supports_lock($fp) ? '1' : '0', "\n";
fclose($fp);

$path = sys_get_temp_dir() . '/phpc_stream_supports_lock.txt';
$tf = fopen($path, 'w+');
echo stream_supports_lock($tf) ? '1' : '0', "\n";
fclose($tf);
@unlink($path);

$bad = 42;
try {
    $r = stream_supports_lock($bad);
    echo "no-error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
1
0
1
TypeError
