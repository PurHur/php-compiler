--TEST--
stdlib dns_get_record() A record assoc key order matches php-src (ext/standard/dns.c, #17039)
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
echo is_array($r) && isset($r[0]) ? implode(',', array_keys($r[0])) : "missing\n";
--EXPECT--
host,class,ttl,type,ip
