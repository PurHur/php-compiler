<?php
/**
 * Issue #24826 — fgetcsv Reflection length/separator/enclosure/escape defaults
 * (ext/standard/file.stub.php).
 */
$r = new ReflectionFunction('fgetcsv');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(), ' defAvail=', (int) $p->isDefaultValueAvailable();
    if ($p->isDefaultValueAvailable()) {
        echo ' ', var_export($p->getDefaultValue(), true);
    }
    if ($p->hasType()) {
        echo ' type=', $p->getType();
    }
    echo "\n";
}
$h = fopen('php://memory', 'r+');
fwrite($h, "a,b\nc,d\n");
rewind($h);
echo 'row=', var_export(fgetcsv($h), true), "\n";
