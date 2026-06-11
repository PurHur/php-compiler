--TEST--
AOT checkdnsrr() — localhost A probe (#5983, #7934)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (!PHPCompiler\ext\standard\VmDns::checkdnsrr('localhost', 'A')) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo checkdnsrr('localhost', 'A') ? "a\n" : "no-a\n";
echo dns_check_record('localhost', 'A') ? "alias\n" : "no-alias\n";
--EXPECT--
a
alias
