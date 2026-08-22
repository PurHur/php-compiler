--TEST--
convert_uudecode Reflection return string|false (VM, issue #25536, string.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('convert_uudecode');
echo 'convert_uudecode=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$enc = convert_uuencode('cat');
echo 'roundtrip=', (convert_uudecode($enc) === 'cat') ? '1' : '0', "\n";
?>
--EXPECT--
convert_uudecode=string|false
roundtrip=1
