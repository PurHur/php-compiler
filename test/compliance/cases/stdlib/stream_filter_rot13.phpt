--TEST--
stdlib stream_filter_append() — string.rot13 on read (#3283, ext/standard/streams.c)
--FILE--
<?php
foreach (['stream_filter_append', 'stream_filter_prepend', 'stream_filter_register'] as $f) {
    echo function_exists($f) ? "1" : "0";
}
echo "\n";
$h = fopen('php://memory', 'r+');
fwrite($h, 'hello');
rewind($h);
$filter = stream_filter_append($h, 'string.rot13');
echo is_resource($filter) ? "filter_res\n" : "no_filter\n";
echo stream_get_contents($h), "\n";
fclose($h);
?>
--EXPECT--
111
filter_res
uryyb
