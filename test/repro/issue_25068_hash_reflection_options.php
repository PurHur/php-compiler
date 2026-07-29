<?php
// Repro #25068 — hash() Reflection marks options required; Zend array $options = []
$r = new ReflectionFunction('hash');
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
echo 'digest=', hash('sha256', 'x'), "\n";
echo 'named=', hash(algo: 'sha256', data: 'x', options: []), "\n";
