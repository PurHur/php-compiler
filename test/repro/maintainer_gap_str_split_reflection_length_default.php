<?php
/**
 * #25044 — str_split Reflection $length default is 1 (not 0).
 * php-src: ext/standard/string.stub.php
 */
$r = new ReflectionFunction('str_split');
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '=?';
    }
    echo "\n";
}
print_r(str_split('ab'));
