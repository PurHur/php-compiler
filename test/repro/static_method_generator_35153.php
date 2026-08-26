<?php
// #35153 — static method generators must match Zend (re-#4938 false fatal)
class C {
    public static function g() {
        yield 1;
        yield 2;
    }
}
foreach (C::g() as $v) {
    echo $v;
}
echo "\n";
