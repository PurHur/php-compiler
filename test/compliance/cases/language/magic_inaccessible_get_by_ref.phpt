--TEST--
Language: by-ref fetch of inaccessible private prop invokes &__get (zend_object_handlers.c, #25688)
--FILE--
<?php
class A {
    private $x = 1;
    public function &__get($n)
    {
        echo "getref:$n\n";
        $y = 99;
        return $y;
    }
}

$a = new A();
$r =& $a->x;
echo "r=$r\n";

// Non-ref read still uses __get.
echo "val=" . $a->x . "\n";

// Visible scope still binds the real slot, not __get.
class C {
    private $x = 5;
    public function &__get($n)
    {
        echo "should-not\n";
        $y = 0;
        return $y;
    }
    public function run()
    {
        $r =& $this->x;
        echo "inside=$r\n";
        $r = 7;
        echo "mutated=" . $this->x . "\n";
    }
}
(new C())->run();

// By-value __get still invokes magic; silence notice for PHPT stdout compare.
class D {
    private $x = 1;
    public function __get($n)
    {
        echo "get:$n\n";
        return 42;
    }
}
$d = new D();
$prev = error_reporting(E_ALL & ~E_NOTICE);
$r4 =& $d->x;
error_reporting($prev);
echo "r4=$r4\n";

// No __get → still Error (keep last so a failed ASSIGN_REF temp cannot alias later news).
class B {
    private $x = 1;
}
try {
    $b = new B();
    $r2 =& $b->x;
    echo "fail\n";
} catch (Error $e) {
    echo "err:" . $e->getMessage() . "\n";
}
--EXPECT--
getref:x
r=99
getref:x
val=99
inside=5
mutated=7
get:x
r4=42
err:Cannot access private property B::$x
