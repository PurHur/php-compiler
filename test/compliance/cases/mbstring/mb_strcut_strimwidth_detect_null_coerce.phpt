--TEST--
mb_strcut/mb_strimwidth/mb_detect_encoding null $string soft-coerce without 8.4 profile (#20225)
--FILE--
<?php
echo var_export(mb_strcut(null, 0), true), "\n";
echo var_export(mb_strimwidth(null, 0, 5), true), "\n";
echo var_export(mb_detect_encoding(null), true), "\n";
?>
--EXPECT--
''
''
'ASCII'
