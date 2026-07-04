--TEST--
language logical && with named local str_contains guard — local survives merge (#15183, Zend/zend_operators.c)
--FILE--
<?php
declare(strict_types=1);

$out = 'hello';
if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
    echo "branch\n";
}
var_export($out);
echo "\n";
?>
--EXPECT--
'hello'
