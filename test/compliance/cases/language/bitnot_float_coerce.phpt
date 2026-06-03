--TEST--
Language: ~ on float truncates to int (zend_operators.c, #5270)
--FILE--
<?php
var_dump(~1.5);
?>
--EXPECT--
int(-2)
