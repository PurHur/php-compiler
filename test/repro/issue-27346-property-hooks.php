<?php
class Point {
    private int $_x;
    public function __construct(int $x) { $this->_x = $x; }
    public int $x {
        get => $this->_x;
        set (int $value) { $this->_x = $value; }
    }
}
$p = new Point(1);
echo $p->x, "\n";
$p->x = 5;
echo $p->x, "\n";
