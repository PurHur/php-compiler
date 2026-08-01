<?php
declare(strict_types=1);

// #26234 — filter_has_var Reflection input_type/var_name (php-src filter.stub.php)
try {
    var_export(filter_has_var(input_type: INPUT_GET, var_name: 'nosuch'));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";
$r = new ReflectionFunction('filter_has_var');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
