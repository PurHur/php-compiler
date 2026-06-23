--TEST--
stdlib iconv() UTF-8 to UTF-16LE round-trip (#10569)
--FILE--
<?php
declare(strict_types=1);
$le = iconv('UTF-8', 'UTF-16LE', 'a');
var_export(bin2hex($le));
echo "\n";
$back = iconv('UTF-16LE', 'UTF-8', $le);
var_export($back);
echo "\n";
?>
--EXPECT--
'6100'
'a'
