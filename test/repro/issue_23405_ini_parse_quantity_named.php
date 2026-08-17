<?php
/**
 * #23405 — ini_parse_quantity Reflection names + Zend-style named args
 * php-src: ext/standard/basic_functions.stub.php
 */
$r = new ReflectionFunction('ini_parse_quantity');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'names=', implode(',', $names), "\n";
echo 'pos=', ini_parse_quantity('10k'), "\n";
try {
    echo 'named=', ini_parse_quantity(shorthand: '10k'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
