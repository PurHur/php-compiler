--TEST--
stdlib gethostbyaddr() loopback reverse DNS (issue #5854)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbyaddr('127.0.0.1') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('gethostbyaddr') ? "fn\n" : "no-fn\n";
$host = gethostbyaddr('127.0.0.1');
echo is_string($host) ? "string\n" : "not-string\n";
echo is_string($host) && $host !== '' ? "nonempty\n" : "empty\n";
echo gethostbyaddr('not-an-ip') === false ? "bad-ip\n" : "ok-ip\n";
--EXPECT--
PHP Warning:  Address is not a valid IPv4 or IPv6 address
fn
string
nonempty
bad-ip
