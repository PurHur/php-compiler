<?php
// Repro #23626 — openssl_random_pseudo_bytes Zend stub named params (length/strong_result)
$names = [];
foreach ((new ReflectionFunction('openssl_random_pseudo_bytes'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$strong = false;
$bytes = openssl_random_pseudo_bytes(length: 4, strong_result: $strong);
$ok = ['length', 'strong_result'] === $names
    && 4 === strlen($bytes)
    && true === $strong;
try {
    openssl_random_pseudo_bytes(length: 4, returned_strong_result: $strong);
    $legacyRejected = false;
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $returned_strong_result');
}
echo ($ok && $legacyRejected) ? "ok\n" : "fail\n";
