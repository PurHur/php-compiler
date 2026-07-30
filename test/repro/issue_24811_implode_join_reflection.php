<?php
/**
 * Issue #24811 — implode/join Reflection vs php-src string.stub.php.
 */
foreach (['implode', 'join'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo "== $fn ==\n";
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ' type=', (string) $p->getType(),
            ' opt=', (int) $p->isOptional(),
            ' defAvail=', (int) $p->isDefaultValueAvailable();
        if ($p->isDefaultValueAvailable()) {
            echo ' ', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
echo 'legacy=', implode(['a', 'b']), "\n";
echo 'named=', implode(separator: '-', array: ['a', 'b']), "\n";
