--TEST--
Language: __PROPERTY__ magic constant in property hooks (PHP 8.4, issue #5978)
--FILE--
<?php
class C {
    public string $p {
        get => __PROPERTY__;
    }
}
$c = new C();
echo $c->p, "\n";
--EXPECT--
p
