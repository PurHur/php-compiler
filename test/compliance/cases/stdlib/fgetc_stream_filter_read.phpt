--TEST--
stdlib fgetc() applies STREAM_FILTER_READ; stream_filter_remove() restores raw reads (#16129, #14225, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
rewind($fp);
$filter = stream_filter_append($fp, 'string.toupper', STREAM_FILTER_READ);
echo fgetc($fp);
echo stream_get_contents($fp);
stream_filter_remove($filter);
rewind($fp);
echo stream_get_contents($fp), "\n";
fclose($fp);
?>
--EXPECT--
HELLOhello
