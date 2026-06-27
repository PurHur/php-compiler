--TEST--
stdlib count() on Countable — zval_get_long coercion for scalar return (#12867)
--FILE--
<?php
class C implements Countable {
    public function count() {
        return '3';
    }
}
echo count(new C()), "\n";
class D implements Countable {
    public function count() {
        return 3.7;
    }
}
echo count(new D()), "\n";
class E implements Countable {
    public function count() {
        return 'abc';
    }
}
echo count(new E()), "\n";
--EXPECT--
3
3
0
