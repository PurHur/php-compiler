--TEST--
hash_update Reflection return true under PROFILE≥8.4 (VM, issue #28742, hash.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('hash_update');
echo (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo ($p->hasType() ? (string) $p->getType() : '-'), ' $', $p->getName(), "\n";
}

$ctx = hash_init('md5');
$ok = hash_update($ctx, 'x');
echo 'runtime=', true === $ok ? 'true' : var_export($ok, true), "\n";
?>
--EXPECT--
true
HashContext $context
string $data
runtime=true
