--TEST--
Language: trait method static locals — per-class binding (#6660, Zend/zend_traits.c)
--FILE--
<?php
trait Counter {
    public function f(): void {
        static $n = 0;
        $n++;
        echo $n, "\n";
    }
}
class C1 { use Counter; }
class C2 { use Counter; }

(new C1())->f();
(new C1())->f();
(new C2())->f();
--EXPECT--
1
2
1
