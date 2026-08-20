<?php

declare(strict_types=1);

/** #24376 — setlocale() named locales: must match Zend positional. */
$r = new ReflectionFunction('setlocale');
foreach ($r->getParameters() as $p) {
    echo '['.$p->getName().']';
}
echo "\n";
try {
    var_export(setlocale(category: LC_ALL, locales: 'C'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
