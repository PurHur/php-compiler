<?php
/**
 * #26106 — is_iterable()/is_countable() Reflection mixed $value → bool (basic_functions.stub.php).
 */
foreach (['is_iterable', 'is_countable'] as $f) {
    $rf = new ReflectionFunction($f);
    $p = $rf->getParameters()[0];
    echo $f,
        ' param=', $p->hasType() ? (string) $p->getType() : '(none)',
        ' return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)',
        "\n";
}
echo 'is_iterable_named=', var_export(is_iterable(value: []), true), "\n";
echo 'is_countable_named=', var_export(is_countable(value: []), true), "\n";
