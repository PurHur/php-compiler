--TEST--
stdlib fgets() applies STREAM_FILTER_READ chain (#14225, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, "hello\n");
rewind($fp);
stream_filter_append($fp, 'string.toupper', STREAM_FILTER_READ);
echo fgets($fp);
fclose($fp);

$fp2 = fopen('php://memory', 'r+');
fwrite($fp2, "hello\n");
rewind($fp2);
stream_filter_append($fp2, 'string.rot13', STREAM_FILTER_READ);
echo fgets($fp2);
?>
--EXPECT--
HELLO
uryyb
