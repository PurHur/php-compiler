--TEST--
openssl_random_pseudo_bytes Reflection int length + untyped strong_result (VM, issue #28858, openssl.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('openssl_random_pseudo_bytes');
$parts = [];
foreach ($r->getParameters() as $p) {
    $parts[] = ($p->isPassedByReference() ? '&' : '')
        . $p->getName()
        . ':' . ($p->hasType() ? (string) $p->getType() : 'none')
        . ($p->isOptional() ? '?' : '');
}
echo implode(',', $parts), PHP_EOL;
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$strong = false;
$bytes = openssl_random_pseudo_bytes(length: 8, strong_result: $strong);
echo 'named=', (8 === strlen($bytes) && true === $strong) ? 'ok' : 'fail', PHP_EOL;
?>
--EXPECT--
length:int,&strong_result:none?
return=string
named=ok
