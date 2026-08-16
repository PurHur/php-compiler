<?php
/**
 * #23333 — vsprintf/vprintf Reflection + named values: match Zend stubs.
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['vsprintf', 'vprintf'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

echo vsprintf(format: '%s-%s', values: ['a', 'b']), "\n";
ob_start();
vprintf(format: '%s', values: ['ok']);
echo ob_get_clean(), "\n";

try {
    vsprintf(format: '%s', args: ['x']);
    echo "args accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
