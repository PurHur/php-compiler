<?php
class A {
    public int $x;
    public function __construct() { $this->x = 1; }
}
class B extends A {
    public function __construct() {}
}
try {
    $b = new B;
    echo 'x=', $b->x, "\n";
} catch (Error $e) {
    echo 'msg=', $e->getMessage(), "\n";
}
echo "after\n";
