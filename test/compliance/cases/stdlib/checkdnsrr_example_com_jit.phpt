--TEST--
stdlib checkdnsrr() / dns_check_record() JIT example.com MX (issue #5983)
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
$mx = checkdnsrr('example.com', 'MX');
echo is_bool($mx) ? "mx-bool\n" : "mx-not-bool\n";
echo $mx ? "mx-true\n" : "mx-false\n";
$alias = dns_check_record('example.com', 'MX');
echo $alias === $mx ? "alias-match\n" : "alias-mismatch\n";
echo checkdnsrr('no-such-phpc-host.invalid.', 'MX') ? "bad-mx\n" : "bad-no-mx\n";
--EXPECT--
mx-bool
mx-true
alias-match
bad-no-mx
