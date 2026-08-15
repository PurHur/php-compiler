--TEST--
AOT iconv() null encodings coerce like Zend (#31309; DEP verified on stderr)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo var_export(iconv(null, 'UTF-8', 'a'), true), "\n";
echo var_export(iconv('UTF-8', null, 'a'), true), "\n";
?>
--EXPECT--
'a'
'a'
