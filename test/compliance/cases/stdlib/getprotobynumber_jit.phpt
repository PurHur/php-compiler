--TEST--
stdlib getprotobynumber() / getservbyport() JIT (issue #3650)
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
echo $p === 'tcp' ? "tcp\n" : "bad-proto\n";
$s = getservbyport(80, 'tcp');
echo $s === 'http' ? "http\n" : "bad-serv\n";
--EXPECT--
tcp
http
