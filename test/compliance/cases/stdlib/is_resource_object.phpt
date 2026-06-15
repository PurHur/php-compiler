--TEST--
stdlib is_resource() / get_resource_type() — Resource object stream handles (#7075, PHP 8.4)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
var_dump(is_resource($h));
echo get_resource_type($h), "\n";
$filter = stream_filter_append($h, 'string.rot13');
var_dump(is_resource($filter));
echo get_resource_type($filter), "\n";
fclose($h);
?>
--EXPECT--
bool(true)
stream
bool(true)
stream filter
