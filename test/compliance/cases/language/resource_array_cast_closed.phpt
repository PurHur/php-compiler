--TEST--
language (array) cast on closed stream resource — embeds stale resource (#15013, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fclose($h);
$closed = (array) $h;
echo gettype($closed[0]), "\n";
echo get_resource_type($closed[0]), "\n";
?>
--EXPECT--
resource (closed)
Unknown
