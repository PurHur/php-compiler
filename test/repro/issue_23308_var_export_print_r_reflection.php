<?php
/**
 * #23308 — var_export/print_r Reflection + named value: match Zend stubs.
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['var_export', 'print_r'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

echo var_export(value: ['a' => 1], return: true), "\n";
echo print_r(value: [1], return: true);

try {
    var_export(var: 1, return: true);
    echo "var accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
