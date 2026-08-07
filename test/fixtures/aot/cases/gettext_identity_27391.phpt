--TEST--
gettext() identity msgid under thin AOT (#27391)
--FILE--
<?php
var_dump(function_exists('gettext'));
echo gettext('hello'), "\n";
?>
--EXPECT--
bool(true)
hello
