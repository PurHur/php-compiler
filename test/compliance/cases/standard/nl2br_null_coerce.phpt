--TEST--
stdlib nl2br() null $string coerces to empty string (#18697, ext/standard/string.c)
--FILE--
<?php
var_export(nl2br(null));
echo "\n";
?>
--EXPECT--
''
