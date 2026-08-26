<?php
// #35153 — inherited static generator via parent Class::g()
class A {
    public static function g() {
        yield 1;
        yield 2;
    }
}
class B extends A {}
foreach (B::g() as $v) {
    echo $v;
}
echo "\n";
