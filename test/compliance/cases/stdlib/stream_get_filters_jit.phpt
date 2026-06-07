--TEST--
Stdlib: stream_get_filters() returns built-in filter names (JIT, #5523, streams.c)
--FILE--
<?php
$filters = stream_get_filters();
echo in_array('string.rot13', $filters, true) ? '1' : '0';
echo in_array('zlib.*', $filters, true) ? '1' : '0';
echo count($filters) >= 8 ? '1' : '0';
echo "\n";
--EXPECT--
111
