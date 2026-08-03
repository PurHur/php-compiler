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
