--TEST--
AOT checkdnsrr() — example.com MX probe (#5983)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (!PHPCompiler\ext\standard\VmDns::checkdnsrr('example.com', 'MX')
    && (!function_exists('checkdnsrr') || !@checkdnsrr('example.com', 'MX'))) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo checkdnsrr('example.com', 'MX') ? "mx\n" : "no-mx\n";
echo dns_check_record('example.com', 'MX') ? "alias\n" : "no-alias\n";
--EXPECT--
mx
alias
