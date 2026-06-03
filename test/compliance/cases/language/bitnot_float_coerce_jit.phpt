--TEST--
Language: ~ on float truncates to int (JIT, #5270)
--FILE--
<?php
var_dump(~1.5);
?>
--EXPECT--
int(-2)
