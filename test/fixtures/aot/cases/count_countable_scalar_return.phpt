--TEST--
AOT count() on Countable — zval_get_long scalar return (#12867)
--FILE--
<?php
class C implements Countable {
    public function count() {
        return '3';
    }
}
echo count(new C()), "\n";
--EXPECT--
3
