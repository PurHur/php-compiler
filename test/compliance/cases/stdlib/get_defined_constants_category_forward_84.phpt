--TEST--
stdlib get_defined_constants() category named param rejected on PROFILE=8.4 — php-src never shipped it (#28522, re-#17436)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('get_defined_constants');
echo 'arity=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), "\n";
}
try {
    get_defined_constants(category: 'Core');
    echo "named=ok\n";
} catch (Error $e) {
    echo 'named=', $e->getMessage(), "\n";
}
try {
    get_defined_constants(false, 'user');
    echo "argc2=ok\n";
} catch (Throwable $e) {
    echo 'argc2=', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
arity=1
$categorize
named=Unknown named parameter $category
argc2=ArgumentCountError: get_defined_constants() expects at most 1 argument, 2 given
