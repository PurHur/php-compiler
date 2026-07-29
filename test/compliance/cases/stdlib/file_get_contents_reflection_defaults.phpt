--TEST--
stdlib file_get_contents Reflection context/length defaults (#24814)
--FILE--
<?php
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
$f = tempnam(sys_get_temp_dir(), 'fgc_phpt');
file_put_contents($f, 'hello');
echo 'omit=', var_export(file_get_contents($f), true), "\n";
@unlink($f);
?>
--EXPECT--
filename opt=0 defAvail=0 type=string
use_include_path opt=1 defAvail=1 false type=bool
context opt=1 defAvail=1 NULL
offset opt=1 defAvail=1 0 type=int
length opt=1 defAvail=1 NULL type=?int
fopen.context defAvail=1 NULL
rmdir.context defAvail=1 NULL
omit='hello'
