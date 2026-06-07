--TEST--
Stdlib: ReflectionException built-in class — missing class/member throws ReflectionException (#7344)
--FILE--
<?php
declare(strict_types=1);

echo (int) class_exists('ReflectionException', false), "\n";

try {
    new ReflectionClass('NoSuchClass_xyz');
} catch (ReflectionException $e) {
    echo 'class ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'class wrong ', get_class($e), "\n";
}

class C {}
try {
    new ReflectionMethod(C::class, 'nope');
} catch (ReflectionException $e) {
    echo 'method ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'method wrong ', get_class($e), "\n";
}

try {
    new ReflectionProperty(C::class, 'nope');
} catch (ReflectionException $e) {
    echo 'property ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'property wrong ', get_class($e), "\n";
}
--EXPECT--
1
class Class "NoSuchClass_xyz" does not exist
method Method C::nope() does not exist
property Property C::$nope does not exist
