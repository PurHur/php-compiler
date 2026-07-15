--TEST--
stdlib strtr(null) — null coerces to empty string on default profile (#18981, ext/standard/string.c)
--FILE--
<?php
echo var_export(strtr(null, 'ab', 'cd'), true), "\n";
echo var_export(strtr(null, ['a' => 'b']), true), "\n";
echo var_export(strtr('abc', 'a', 'A'), true), "\n";
?>
--EXPECT--
''
''
'Abc'
