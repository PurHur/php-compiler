--TEST--
stdlib addcslashes() null $characters without strict_types — coerce to empty charlist (#17829, ext/standard/string.c)
--FILE--
<?php
var_dump(addcslashes('abc', null));
?>
--EXPECT--
string(3) "abc"
