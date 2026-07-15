--TEST--
image getimagesizefromstring('not-an-image') — silent false when >=12 bytes (#18572, ext/standard/image.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$result = @getimagesizefromstring('not-an-image');
$last = error_get_last();
var_export($result);
echo "\n";
var_export($last);
echo "\n";
?>
--EXPECT--
false
NULL
