<?php
/**
 * Issue #25144 — getopt(short_options:, rest_index:) named hole + Reflection
 * (ext/standard/basic_functions.stub.php / php_getopt.c).
 */
$_SERVER['argv'] = ['prog', '-a', 'foo'];
$ri = -1;
try {
    var_export(getopt(short_options: 'a', rest_index: $ri));
    echo ' ri=', $ri, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$r = new ReflectionFunction('getopt');
echo 'argc=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isPassedByReference()) {
        echo ' REF';
    }
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
