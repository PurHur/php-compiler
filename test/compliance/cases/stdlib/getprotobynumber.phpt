--TEST--
stdlib getprotobynumber() / getservbyport() (issue #3650)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmNetworkServices.php';
if (PHPCompiler\ext\standard\VmNetworkServices::getprotobynumber(6) === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('getprotobynumber') ? "proto-fn\n" : "no-proto-fn\n";
echo function_exists('getservbyport') ? "serv-fn\n" : "no-serv-fn\n";
$p = getprotobynumber(6);
echo $p === 'tcp' ? "tcp\n" : "bad-proto\n";
$s = getservbyport(80, 'tcp');
echo $s === 'http' ? "http\n" : "bad-serv\n";
echo getprotobynumber(99999) === false ? "proto-miss\n" : "proto-hit\n";
echo getservbyport(99999, 'tcp') === false ? "serv-miss\n" : "serv-hit\n";
--EXPECT--
proto-fn
serv-fn
tcp
http
proto-miss
serv-miss
