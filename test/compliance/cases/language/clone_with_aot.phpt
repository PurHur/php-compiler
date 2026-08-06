--TEST--
Language: clone $obj with { prop: val } user-script AOT (#23046, re-#19130, PHP 8.5)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || '8.5' !== getenv('PHP_COMPILER_PROFILE')) {
    die('skip requires PHP_COMPILER_PROFILE=8.5 clone-with gate');
}
--FILE--
<?php
class C {
    public int $x;
    public int $y;
    public function __construct(int $x, int $y = 0) {
        $this->x = $x;
        $this->y = $y;
    }
}
$a = new C(1, 2);
$b = clone $a with { x: 9 };
echo $b->x, ',', $b->y, "\n";
$p = new C(1, 2);
$q = clone ($p, ['x' => 9]);
echo $q->x, ',', $q->y, "\n";
--EXPECT--
9,2
9,2
