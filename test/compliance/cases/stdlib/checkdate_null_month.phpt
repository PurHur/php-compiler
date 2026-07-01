--TEST--
stdlib checkdate() null month coerces to false (#14674, ext/standard/datetime.c)
--FILE--
<?php
var_dump(checkdate(null, 1, 2020));
?>
--EXPECT--
bool(false)
