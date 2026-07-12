--TEST--
stdlib stream_filter_remove() — default append keeps read filter after write filter removed (#18289, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'abc');
rewind($fp);
$filter = stream_filter_append($fp, 'string.toupper');
var_export(stream_filter_remove($filter));
echo "\n";
rewind($fp);
echo stream_get_contents($fp), "\n";
?>
--EXPECT--
true
ABC
