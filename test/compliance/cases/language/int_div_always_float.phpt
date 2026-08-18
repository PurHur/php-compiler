--TEST--
Language: integer `/` always promotes to float (zend_div, #31968)
--FILE--
<?php
var_dump(7 / 2);
var_dump(5 / 2);
?>
--EXPECT--
float(3.5)
float(2.5)
