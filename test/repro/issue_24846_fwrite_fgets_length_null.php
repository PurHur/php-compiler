<?php
/**
 * Issue #24846 — fwrite/fgets Reflection ?int $length = null (ext/standard/file.stub.php).
 */
foreach (['fwrite', 'fgets'] as $f) {
    echo "== $f ==\n";
    $r = new ReflectionFunction($f);
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ' opt=', (int) $p->isOptional();
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        if ($p->hasType()) {
            echo ' type=', $p->getType();
        }
        echo "\n";
    }
}
$h = fopen('php://memory', 'r+');
fwrite($h, 'hello');
rewind($h);
echo 'fgets_named=', var_export(fgets(stream: $h, length: 3), true), "\n";
rewind($h);
echo 'fgets_omit=', var_export(fgets($h), true), "\n";
$h2 = fopen('php://memory', 'r+');
echo 'fwrite_omit=', var_export(fwrite($h2, 'abc'), true), "\n";
