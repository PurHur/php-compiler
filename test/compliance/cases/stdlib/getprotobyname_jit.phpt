--TEST--
stdlib getprotobyname() / getservbyname() JIT (issue #6218)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmNetworkServices.php';
if (PHPCompiler\ext\standard\VmNetworkServices::getprotobyname('tcp') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
$p = getprotobyname('tcp');
echo $p === 6 ? "tcp_num\n" : "bad-proto\n";
$s = getservbyname('http', 'tcp');
echo $s === 80 ? "http_port\n" : "bad-serv\n";
--EXPECT--
tcp_num
http_port
