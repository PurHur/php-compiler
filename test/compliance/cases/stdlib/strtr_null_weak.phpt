--TEST--
stdlib strtr(null) — weak caller coerces empty string (#19017, ext/standard/string.c)
--FILE--
<?php
echo var_export(strtr(null, 'ab', 'cd'), true), "\n";
echo var_export(strtr(null, ['a' => 'b']), true), "\n";
?>
--EXPECT--
''
''
