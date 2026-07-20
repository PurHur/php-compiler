--TEST--
PHP 8.4 asymmetric visibility on interface properties (#4876)
--FILE--
<?php
interface I {
    public (private(set)) string $slug;
}
class C implements I {
    public string $slug = 'b';
}
$c = new C();
echo $c->slug, "\n";
try {
    $c->slug = 'x';
    echo "set ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
b
Cannot modify private(set) property C::$slug from global scope
