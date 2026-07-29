<?php
// Repro #25018 — hash_hkdf Reflection required/optional defaults + types (re-#23290)
$r = new ReflectionFunction('hash_hkdf');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
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
echo 'two_arg_len=', strlen(hash_hkdf('sha256', 'ikm')), "\n";
echo 'named_len=', strlen(hash_hkdf(algo: 'sha256', key: 'ikm', length: 8)), "\n";
