--TEST--
AOT: typed property get-only hook under PROFILE=8.4 (#27346)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Point {
    private int $_x;
    public function __construct(int $x) { $this->_x = $x; }
    public int $x {
        get => $this->_x;
    }
}
$p = new Point(1);
echo $p->x, "\n";
--EXPECT--
1
