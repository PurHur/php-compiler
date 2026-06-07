--TEST--
stdlib gethostbyname() localhost without host ext/ffi (#7315)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbynamel('localhost') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
$ip = gethostbyname('localhost');
echo is_string($ip) ? "string\n" : "not-string\n";
echo preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip) ? "ipv4\n" : "not-ipv4\n";
--EXPECT--
string
ipv4
