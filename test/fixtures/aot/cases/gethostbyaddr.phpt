--TEST--
AOT gethostbyaddr() loopback (issue #5854)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbyaddr('127.0.0.1') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
$a = gethostbyaddr('127.0.0.1');
$b = gethostbyaddr('127.0.0.1');
echo is_string($a) && is_string($b) && $a === $b ? "stable\n" : "bad\n";
--EXPECT--
stable
