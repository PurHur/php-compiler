--TEST--
stdlib sprintf() null format without strict_types JIT — coerce to empty string (#16514, ext/standard/sprintf.c)
--JIT--
--FILE--
<?php
var_dump(sprintf(null));
?>
--EXPECT--
string(0) ""
