--TEST--
language (array) cast on stream resource — embeds resource at index 0 (#15012, #15013)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$open = (array) $h;
echo is_resource($open[0]) ? 'open_res' : 'open_null';
echo "\n";
echo get_resource_type($open[0]);
echo "\n";
fclose($h);
$closed = (array) $h;
echo gettype($closed[0]);
echo "\n";
echo get_resource_type($closed[0]);
echo "\n";
var_export((array) $h);
echo "\n";
--EXPECT--
open_res
stream
resource (closed)
Unknown
array (
  0 => NULL,
)
