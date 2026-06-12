--TEST--
Stdlib: class_uses() — Z_PARAM_BOOL autoload coercion (VM, #4837, ext/standard/spl_functions.c)
--FILE--
<?php
class C {}
try {
    class_uses(C::class, []);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
trait T {}
class UsesT {
    use T;
}
$map = class_uses(UsesT::class, 1);
echo isset($map['T']) ? "ok\n" : "fail\n";
--EXPECT--
TypeError: class_uses(): Argument #2 ($autoload) must be of type bool, array given
ok
