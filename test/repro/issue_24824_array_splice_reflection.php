<?php
/**
 * Issue #24824 — array_splice Reflection length=?int/NULL, replacement=mixed (ext/standard/array.stub.php).
 */
$r = new ReflectionFunction('array_splice');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->isOptional() ? 'opt' : 'req';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo ' typ=', $p->hasType() ? (string) $p->getType() : '-', "\n";
}
$a = [1, 2, 3, 4];
$removed = array_splice(array: $a, offset: 1, replacement: ['x']);
echo 'named=', json_encode($removed), '/', json_encode($a), "\n";
