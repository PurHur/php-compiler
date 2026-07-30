<?php
/**
 * #24813 — str_getcsv Reflection separator/enclosure/escape defaults.
 * php-src: ext/standard/basic_functions.stub.php
 */
$r = new ReflectionFunction('str_getcsv');
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '=?';
    }
    echo "\n";
}
var_export(str_getcsv('a,b'));
echo "\n";
