<?php
// Issue #24845 — date/gmdate/strtotime Reflection ?int timestamp = null (ext/date/php_date.stub.php).
foreach (['date', 'gmdate', 'strtotime'] as $f) {
    echo "== $f ==\n";
    $r = new ReflectionFunction($f);
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ' opt=', (int) $p->isOptional();
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        } else {
            echo ' def=n/a';
        }
        echo ' type=', $p->hasType() ? (string) $p->getType() : 'none';
        echo "\n";
    }
}
$omit = date('Y-m-d');
$zero = date('Y-m-d', 0);
echo 'omit_not_epoch=', ($omit !== $zero) ? '1' : '0', "\n";
echo 'omit_matches_now=', ($omit === date('Y-m-d')) ? '1' : '0', "\n";
