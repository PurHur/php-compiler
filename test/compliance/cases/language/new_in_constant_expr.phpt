--TEST--
Language: new in constant expression (PHP 8.3) — const X = new C(1) (#9116)
--FILE--
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
var_dump(Holder::X->n);
--EXPECT--
int(1)
