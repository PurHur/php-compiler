--TEST--
Language: parent::/self:: instanceMethod(...) FCC invoke forwards args (#27834, re-#17655, zend_closures.c)
--FILE--
<?php
class A {
    public $v = 'A';
    public function foo($x) {
        return $this->v . ':' . $x;
    }
    public static function bar($x) {
        return 'A:' . $x;
    }
}
class B extends A {
    public $v = 'B';
    public function foo($x) {
        return $this->v . ':' . $x;
    }
    public function viaParent() {
        $f = parent::foo(...);
        echo get_class($f), "\n";
        echo $f('z'), "\n";
    }
    public function viaSelf() {
        $f = self::foo(...);
        echo $f('w'), "\n";
    }
    public function viaStaticParent() {
        $f = parent::bar(...);
        echo $f('s'), "\n";
    }
}
(new B)->viaParent();
(new B)->viaSelf();
(new B)->viaStaticParent();
--EXPECT--
Closure
B:z
B:w
A:s
