--TEST--
stdlib stream_filter_remove() — detach write filter (#6040, ext/standard/streams.c)
--FILE--
<?php
echo function_exists('stream_filter_remove') ? "1" : "0";
echo "\n";
$fp = fopen('php://memory', 'w+');
$filter = stream_filter_append($fp, 'string.toupper', STREAM_FILTER_WRITE);
echo is_resource($filter) ? "filter_res\n" : "no_filter\n";
fwrite($fp, 'hi');
var_export(stream_filter_remove($filter));
echo "\n";
var_export(is_resource($filter));
echo "\n";
fwrite($fp, '!');
rewind($fp);
echo stream_get_contents($fp), "\n";
var_export(stream_filter_remove($filter));
echo "\n";
fclose($fp);
?>
--EXPECT--
1
filter_res
true
false
HI!
false
