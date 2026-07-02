--TEST--
language (array) cast on open stream resource — embeds live resource (#15012, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$open = (array) $h;
var_dump(is_resource($open[0]));
echo get_resource_type($open[0]), "\n";
?>
--EXPECT--
bool(true)
stream
