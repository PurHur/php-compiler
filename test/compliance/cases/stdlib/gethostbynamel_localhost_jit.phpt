--TEST--
stdlib gethostbynamel() JIT localhost (issue #3707)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (PHPCompiler\ext\standard\VmDns::gethostbynamel('localhost') === false) {
    echo "skip\n";
}
?>
--FILE--
<?php
$a = gethostbynamel('localhost');
$b = gethostbynamel('localhost');
echo $a !== false && $b !== false && $a[0] === $b[0] ? "stable\n" : "bad\n";
--EXPECT--
stable
