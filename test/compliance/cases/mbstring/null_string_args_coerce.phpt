--TEST--
mbstring null $string operands coerce when non-strict (#18273, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_strlen(null), "\n";
echo var_export(mb_substr(null, 0), true), "\n";
echo var_export(mb_strtolower(null), true), "\n";
?>
--EXPECT--
0
''
''
