--TEST--
language ReflectionFunction/Method::invoke() named args (#24949, php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

function f($a = 1, $b = 2) {
    echo "$a|$b\n";
}
(new ReflectionFunction('f'))->invoke(b: 9);
(new ReflectionFunction('f'))->invoke(10, b: 20);
try {
    (new ReflectionFunction('f'))->invoke(z: 1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class C {
    public function m($a = 1, $b = 2) {
        echo "$a|$b\n";
    }
}
$obj = new C();
(new ReflectionMethod(C::class, 'm'))->invoke($obj, b: 9);
(new ReflectionMethod(C::class, 'm'))->invoke($obj, 7, b: 8);
--EXPECT--
1|9
10|20
Unknown named parameter $z
1|9
7|8
