<?php
// Repro #25469 — hash_pbkdf2 Reflection types / optionality / return (ext/hash/hash.stub.php)
$r = new ReflectionFunction('hash_pbkdf2');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
$omit = hash_pbkdf2('sha256', 'p', 's', 1000);
$named = hash_pbkdf2(algo: 'sha256', password: 'p', salt: 's', iterations: 1000, length: 16);
echo 'arity4=', substr($omit, 0, 8), "\n";
echo 'named_len=', strlen($named), "\n";
