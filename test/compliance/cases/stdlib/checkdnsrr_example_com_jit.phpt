--TEST--
stdlib checkdnsrr() / dns_check_record() JIT localhost A (issue #5983, #7934)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (!PHPCompiler\ext\standard\VmDns::checkdnsrr('localhost', 'A')) {
    echo "skip\n";
}
?>
--FILE--
<?php
$a = checkdnsrr('localhost', 'A');
echo is_bool($a) ? "a-bool\n" : "a-not-bool\n";
echo $a ? "a-true\n" : "a-false\n";
$alias = dns_check_record('localhost', 'A');
echo $alias === $a ? "alias-match\n" : "alias-mismatch\n";
echo checkdnsrr('no-such-phpc-host.invalid.', 'MX') ? "bad-mx\n" : "bad-no-mx\n";
--EXPECT--
a-bool
a-true
alias-match
bad-no-mx
