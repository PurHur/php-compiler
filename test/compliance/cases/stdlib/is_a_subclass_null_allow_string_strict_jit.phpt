--TEST--
stdlib is_a/is_subclass_of(null $allow_string) under strict_types TypeError JIT (#31339)
--FILE--
<?php
declare(strict_types=1);
try {
    is_a(new stdClass(), 'stdClass', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    is_subclass_of(new class extends stdClass {}, 'stdClass', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo is_a(new stdClass(), 'stdClass') ? "ok\n" : "fail\n";
--EXPECT--
is_a(): Argument #3 ($allow_string) must be of type bool, null given
is_subclass_of(): Argument #3 ($allow_string) must be of type bool, null given
ok
