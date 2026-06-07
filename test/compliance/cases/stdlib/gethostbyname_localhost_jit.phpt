--TEST--
stdlib gethostbyname() JIT localhost (issue #7419)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbynamel('localhost') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
$a = gethostbyname('localhost');
$b = gethostbyname('localhost');
echo $a === $b && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $a) ? "stable\n" : "bad\n";
$miss = gethostbyname('no-such-phpc-host.invalid.');
echo $miss === 'no-such-phpc-host.invalid.' ? "miss\n" : "hit\n";
--EXPECT--
stable
miss
