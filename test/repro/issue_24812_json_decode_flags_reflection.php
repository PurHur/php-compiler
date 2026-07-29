<?php
/**
 * Issue #24812 — json_decode Reflection $flags optional default 0 (ext/json/json.stub.php).
 */
$r = new ReflectionFunction('json_decode');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' defAvail=', (int) $p->isDefaultValueAvailable();
    if ($p->isDefaultValueAvailable()) {
        echo ' ', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
var_export(json_decode('{"a":1}', associative: true, flags: JSON_THROW_ON_ERROR));
echo "\n";
