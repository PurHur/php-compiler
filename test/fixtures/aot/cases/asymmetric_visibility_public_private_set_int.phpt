--TEST--
AOT: public private(set) int — read + catchable write guard (#16354, Zend/zend_object_handlers.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
    public function bump(): void {
        $this->x++;
    }
}
$c = new C();
$c->bump();
echo $c->x, "\n";
try {
    $c->x = 5;
    echo "no catch\n";
} catch (Error $e) {
    echo "caught\n";
}
--EXPECT--
2
caught
--EXPECT_EXIT--
0
