--TEST--
stdlib stream_filter_append() — string.toupper on write, JIT (#9047, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://temp', 'w+');
stream_filter_append($fp, 'string.toupper', STREAM_FILTER_WRITE);
fwrite($fp, 'abc');
rewind($fp);
echo stream_get_contents($fp), "\n";
?>
--EXPECT--
ABC
