<?php
declare(strict_types=1);

// Repro for #25470 — hash_equals Reflection stubs vs Zend (ext/hash/hash.stub.php).
$r = new ReflectionFunction('hash_equals');
echo 'ret=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N', "\n";
}
echo 'named=', hash_equals(known_string: 'aa', user_string: 'aa') ? 'true' : 'false', "\n";
