--TEST--
stdlib stream_filter_append() — zlib.deflate on read (#14226, ext/zlib/zlib.c)
--FILE--
<?php
$payload = str_repeat('a', 100);
$fp = fopen('php://memory', 'r+');
fwrite($fp, $payload);
rewind($fp);
$filter = stream_filter_append($fp, 'zlib.deflate', STREAM_FILTER_READ);
echo is_resource($filter) ? "filter_ok\n" : "filter_fail\n";
$out = stream_get_contents($fp);
echo ($out === gzdeflate($payload)) ? "deflate_match\n" : "deflate_mismatch\n";
fclose($fp);

$plain = str_repeat('b', 50);
$fp2 = fopen('php://memory', 'r+');
fwrite($fp2, gzdeflate($plain));
rewind($fp2);
stream_filter_append($fp2, 'zlib.inflate', STREAM_FILTER_READ);
echo stream_get_contents($fp2) === $plain ? "inflate_ok\n" : "inflate_fail\n";
fclose($fp2);
?>
--EXPECT--
filter_ok
deflate_match
inflate_ok
