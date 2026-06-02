--TEST--
AOT getprotobynumber() / getservbyport() (issue #3650)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmNetworkServices.php';
if (PHPCompiler\ext\standard\VmNetworkServices::getprotobynumber(6) === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
$p = getprotobynumber(6);
$s = getservbyport(80, 'tcp');
if ($p === 'tcp' && $s === 'http') {
    echo "ok\n";
} else {
    echo "fail\n";
}
--EXPECT--
ok
