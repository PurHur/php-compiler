--TEST--
iconv() null $string coerces to empty string on default profile (#19015, ext/iconv/iconv.c)
--FILE--
<?php
echo var_export(@iconv(null, null, null), true), "\n";
echo var_export(@iconv('UTF-8', 'UTF-8', null), true), "\n";
?>
--EXPECT--
''
''
