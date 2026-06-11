--TEST--
stdlib checkdnsrr() / dns_check_record() localhost A + invalid MX (issue #5983, #7934)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (!PHPCompiler\ext\standard\VmDns::checkdnsrr('localhost', 'A')) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('checkdnsrr') ? "checkdnsrr-fn\n" : "no-checkdnsrr\n";
echo function_exists('dns_check_record') ? "dns_check_record-fn\n" : "no-dns_check_record\n";
$a = checkdnsrr('localhost', 'A');
echo is_bool($a) ? "a-bool\n" : "a-not-bool\n";
echo $a ? "a-true\n" : "a-false\n";
$alias = dns_check_record('localhost', 'A');
echo $alias === $a ? "alias-match\n" : "alias-mismatch\n";
echo checkdnsrr('no-such-phpc-host.invalid.', 'MX') ? "bad-mx\n" : "bad-no-mx\n";
enum E: int { case A = 1; }
try {
    checkdnsrr(E::A, 'MX');
    echo "enum-accepted\n";
} catch (TypeError $e) {
    echo "enum-te\n";
}
--EXPECT--
checkdnsrr-fn
dns_check_record-fn
a-bool
a-true
alias-match
bad-no-mx
enum-te
