--TEST--
stdlib dns_get_record() localhost deduped A and AAAA (#10817, #10818, ext/standard/dns.c)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (false === PHPCompiler\ext\standard\VmDns::dnsGetRecord('localhost', 1)) {
    echo "skip\n";
}
?>
--FILE--
<?php
$a = dns_get_record('localhost', DNS_A);
echo count($a), "\n";
echo $a[0]['ip'], "\n";
$aaaa = dns_get_record('localhost', DNS_AAAA);
if (false === $aaaa) {
    echo "aaaa-false\n";
} else {
    echo count($aaaa), "\n";
    echo $aaaa[0]['ipv6'], "\n";
}
--EXPECT--
1
127.0.0.1
1
::1
