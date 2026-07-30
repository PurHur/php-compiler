<?php
declare(strict_types=1);

// Repro for #25458 — json_decode/json_encode Reflection stubs vs Zend (ext/json/json.stub.php).
foreach (['json_decode', 'json_encode'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo '== ', $fn, " ==\n";
    echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '?',
            ' opt=', $p->isOptional() ? 'Y' : 'N';
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
echo 'decode=', json_encode(json_decode('{"a":1}', associative: true)), "\n";
echo 'encode=', var_export(json_encode(value: ['x' => 1]), true), "\n";
