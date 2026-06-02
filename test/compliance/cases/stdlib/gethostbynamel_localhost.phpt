--TEST--
stdlib gethostbynamel() localhost IPv4 list (issue #3707)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbynamel('localhost') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('gethostbynamel') ? "fn\n" : "no-fn\n";
$ips = gethostbynamel('localhost');
echo $ips !== false ? "array\n" : "not-array\n";
echo $ips !== false && isset($ips[0]) ? "idx0\n" : "no-idx0\n";
echo $ips !== false && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ips[0]) ? "ipv4\n" : "no-ipv4\n";
echo gethostbynamel('no-such-phpc-host.invalid.') === false ? "miss\n" : "hit\n";
--EXPECT--
fn
array
idx0
ipv4
miss
