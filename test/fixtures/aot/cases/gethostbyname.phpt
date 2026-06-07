--TEST--
AOT gethostbyname() localhost (issue #7419)
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
echo $a === $b && $a !== 'localhost' ? "stable\n" : "bad\n";
--EXPECT--
stable
