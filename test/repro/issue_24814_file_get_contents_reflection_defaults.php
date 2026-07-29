<?php
/**
 * Issue #24814 — file_get_contents Reflection context=NULL, length=?int=NULL
 * (ext/standard/file.stub.php). Also covers fopen/rmdir context defaults.
 */
$r = new ReflectionFunction('file_get_contents');
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
foreach (['fopen', 'rmdir'] as $f) {
    $rp = new ReflectionFunction($f);
    foreach ($rp->getParameters() as $p) {
        if ($p->getName() !== 'context') {
            continue;
        }
        echo $f, '.context defAvail=', (int) $p->isDefaultValueAvailable();
        if ($p->isDefaultValueAvailable()) {
            echo ' ', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
$f = tempnam(sys_get_temp_dir(), 'fgc');
file_put_contents($f, 'hello');
echo 'omit=', var_export(file_get_contents($f), true), "\n";
@unlink($f);
