<?php
// Repro #23290 — hash_hkdf Reflection + Zend stub named params
$checks = [];

$rf = new ReflectionFunction('hash_hkdf');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$checks[] = ['algo', 'key', 'length', 'info', 'salt'] === $names;
$checks[] = 5 === $rf->getNumberOfParameters();

$okm = hash_hkdf(algo: 'sha256', key: 'ikm', length: 8, info: 'i', salt: 's');
$checks[] = is_string($okm) && 8 === strlen($okm);
$checks[] = 'b069c08f611a5338' === bin2hex($okm);

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
