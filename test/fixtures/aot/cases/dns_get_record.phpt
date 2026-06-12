--TEST--
AOT: dns_get_record() localhost A compile-time materializer (#6392)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (false === PHPCompiler\ext\standard\VmDns::dnsGetRecord('localhost', 1)) {
    echo "skip\n";
}
?>
--FILE--
<?php
$r = dns_get_record('localhost', DNS_A);
echo is_array($r) ? "arr\n" : "false\n";
if (is_array($r)) {
    echo $r[0]['type'], "\n";
}
--EXPECT--
arr
A
