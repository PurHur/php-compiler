--TEST--
stdlib get_class() arity 1 under PROFILE=8.4 — no phantom allow_string (#28310, Zend/zend_builtin_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('get_class');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}

try {
    echo get_class(new stdClass(), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    get_class(allow_string: true);
    echo "named-ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

echo get_class(new stdClass()), "\n";
--EXPECT--
argc=1
object
ArgumentCountError:get_class() expects at most 1 argument, 2 given
Error:Unknown named parameter $allow_string
stdClass
