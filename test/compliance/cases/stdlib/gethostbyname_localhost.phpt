--TEST--
stdlib gethostbyname() localhost IPv4 (issue #7419)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbynamel('localhost') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('gethostbyname') ? "fn\n" : "no-fn\n";
$ip = gethostbyname('localhost');
echo is_string($ip) ? "string\n" : "not-string\n";
echo preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip) ? "ipv4\n" : "not-ipv4\n";
$miss = gethostbyname('no-such-phpc-host.invalid.');
echo $miss === 'no-such-phpc-host.invalid.' ? "miss-host\n" : "miss-other\n";
--EXPECT--
fn
string
ipv4
miss-host
