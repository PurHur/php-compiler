--TEST--
Language: child constructor cannot initialize parent readonly promoted property (#9714, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Parent_ {
    public function __construct(public readonly string $x) {}
}

class Child extends Parent_ {
    public function __construct(string $x) {
        $this->x = $x;
    }
}

class ChildViaParent extends Parent_ {
    public function __construct(string $x) {
        parent::__construct($x);
    }
}

try {
    new Child('a');
    echo "child: ok\n";
} catch (Throwable $e) {
    echo 'child: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$p = new Parent_('b');
echo $p->x, "\n";

$via = new ChildViaParent('c');
echo $via->x, "\n";
--EXPECT--
child: Error: Cannot initialize readonly property Parent_::$x from Child
b
c
