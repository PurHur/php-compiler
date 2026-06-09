--TEST--
stdlib checkdnsrr() / dns_check_record() example.com MX (issue #5983)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (!function_exists('checkdnsrr') && !extension_loaded('ffi')) {
    echo "skip\n";
}
if (!PHPCompiler\ext\standard\VmDns::checkdnsrr('example.com', 'MX')
    && !@checkdnsrr('example.com', 'MX')) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('checkdnsrr') ? "checkdnsrr-fn\n" : "no-checkdnsrr\n";
echo function_exists('dns_check_record') ? "dns_check_record-fn\n" : "no-dns_check_record\n";
$mx = checkdnsrr('example.com', 'MX');
echo is_bool($mx) ? "mx-bool\n" : "mx-not-bool\n";
echo $mx ? "mx-true\n" : "mx-false\n";
$alias = dns_check_record('example.com', 'MX');
echo $alias === $mx ? "alias-match\n" : "alias-mismatch\n";
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
mx-bool
mx-true
alias-match
bad-no-mx
enum-te
