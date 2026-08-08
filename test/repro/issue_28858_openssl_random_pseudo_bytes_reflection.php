<?php
/**
 * Issue #28858 — openssl_random_pseudo_bytes Reflection matches openssl.stub.php
 * (int $length, untyped &$strong_result = null): string
 */
$r = new ReflectionFunction('openssl_random_pseudo_bytes');
foreach ($r->getParameters() as $p) {
    echo ($p->hasType() ? (string) $p->getType() : '<none>'), ' $', $p->getName(),
        ($p->isPassedByReference() ? ' byref' : ''),
        ($p->isOptional() ? ' opt' : ''),
        PHP_EOL;
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', PHP_EOL;
$strong = false;
$bytes = openssl_random_pseudo_bytes(length: 4, strong_result: $strong);
echo 'named=', (4 === strlen($bytes) && true === $strong) ? 'ok' : 'fail', PHP_EOL;
