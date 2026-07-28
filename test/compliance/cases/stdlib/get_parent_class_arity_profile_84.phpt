--TEST--
stdlib get_parent_class() arity 1 under PROFILE=8.4 — no phantom allow_string (#23948, Zend/zend_builtin_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {}
class B extends A {}

$r = new ReflectionFunction('get_parent_class');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}

try {
    var_export(get_parent_class(B::class, false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export(get_parent_class(object_or_class: B::class, allow_string: false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

echo get_parent_class(B::class), "\n";
--EXPECT--
argc=1
object_or_class
ArgumentCountError:get_parent_class() expects at most 1 argument, 2 given
Error:Unknown named parameter $allow_string
A
