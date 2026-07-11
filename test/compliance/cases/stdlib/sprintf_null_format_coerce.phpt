--TEST--
stdlib sprintf() null format without strict_types — coerce to empty string (#16514, ext/standard/sprintf.c)
--FILE--
<?php
var_dump(sprintf(null));
?>
--EXPECT--
string(0) ""
