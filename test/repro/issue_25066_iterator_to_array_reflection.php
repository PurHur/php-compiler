<?php
// Repro #25066 — iterator_to_array Reflection type + preserve_keys default
$r = new ReflectionFunction('iterator_to_array');
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
$it = new ArrayIterator(['a' => 1, 'b' => 2]);
echo 'keys=', json_encode(iterator_to_array($it)), "\n";
echo 'named=', json_encode(iterator_to_array(iterator: $it, preserve_keys: false)), "\n";
