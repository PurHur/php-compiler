--TEST--
AOT: class_alias() excess argc → ArgumentCountError (#30783)
--FILE--
<?php
class A
{
}
try {
    class_alias('A', 'B', true, 1);
    echo "excess:NO_THROW\n";
} catch (Throwable $e) {
    echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    class_alias('A');
    echo "short:NO_THROW\n";
} catch (Throwable $e) {
    echo 'short:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', var_export(class_alias('A', 'C'), true), "\n";
--EXPECT--
excess:ArgumentCountError:class_alias() expects at most 3 arguments, 4 given
short:ArgumentCountError:class_alias() expects at least 2 arguments, 1 given
ok:true
