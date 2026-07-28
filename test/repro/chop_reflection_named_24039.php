<?php
$r = new ReflectionFunction('chop');
echo 'count=', $r->getNumberOfParameters(), ' names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ';';
}
echo "\n";
echo 'pos=[', chop('  a  '), "]\n";
try {
    echo 'na=[', chop(string: '  a  '), "]\n";
} catch (Throwable $e) {
    echo 'na_err=', $e->getMessage(), "\n";
}
