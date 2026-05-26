--TEST--
stdlib uniqid() JIT/AOT path
--FILE--
<?php
$id = uniqid();
echo preg_match('/^[0-9a-f]{13}$/', $id) ? "base\n" : "bad\n";
$pfx = uniqid('pfx');
echo strncmp($pfx, 'pfx', 3) === 0 && strlen($pfx) === 16 ? "pfx\n" : "bad\n";
$ent = uniqid('', true);
echo strpos($ent, '.') !== false && strlen($ent) === 23 ? "entropy\n" : "bad\n";
--EXPECT--
base
pfx
entropy
