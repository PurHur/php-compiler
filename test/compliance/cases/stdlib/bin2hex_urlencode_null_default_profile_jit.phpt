--TEST--
stdlib bin2hex()/urlencode()/rawurlencode() null coerce on default profile JIT (#18912, ext/standard/string.c, url.c)
--JIT--
--FILE--
<?php
echo var_export(bin2hex(null), true), "\n";
echo var_export(urlencode(null), true), "\n";
echo var_export(rawurlencode(null), true), "\n";
?>
--EXPECT--
''
''
''
