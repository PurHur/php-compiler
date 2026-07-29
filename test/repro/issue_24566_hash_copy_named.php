<?php
/** Repro for #24566 — hash_copy Reflection + Zend named context. */
$r = new ReflectionFunction('hash_copy');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'names=', implode(',', $names), ' arity=', $r->getNumberOfParameters(), "\n";
$c = hash_init('sha1');
hash_update($c, 'x');
try {
    echo 'named=', hash_final(hash_copy(context: $c)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$c2 = hash_init('sha1');
hash_update($c2, 'x');
echo 'pos=', hash_final(hash_copy($c2)), "\n";
