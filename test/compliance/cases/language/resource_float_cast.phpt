--TEST--
language (float) cast on open stream resource — coerce to resource id (#15014, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$id = get_resource_id($h);
var_dump((float) $h === (float) $id);
?>
--EXPECT--
bool(true)
