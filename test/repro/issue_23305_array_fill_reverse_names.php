<?php
/**
 * #23305 — array_fill / array_reverse Reflection names + Zend-style named args
 * php-src: ext/standard/basic_functions.stub.php
 */
$rf = new ReflectionFunction('array_fill');
echo 'array_fill=';
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
try {
    var_export(array_fill(start_index: 0, count: 2, value: 'x'));
    echo "\n";
} catch (Throwable $e) {
    echo 'fill ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_fill(start_key: 0, num: 2, val: 'x');
    echo "legacy fill ok\n";
} catch (Throwable $e) {
    echo 'legacy fill ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rr = new ReflectionFunction('array_reverse');
echo 'array_reverse=';
foreach ($rr->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
try {
    var_export(array_reverse(array: [1, 2], preserve_keys: true));
    echo "\n";
} catch (Throwable $e) {
    echo 'reverse ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_reverse(input: [1, 2], preserve: true);
    echo "legacy reverse ok\n";
} catch (Throwable $e) {
    echo 'legacy reverse ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
