<?php
class Box {
    public $a = [1];
}
$box = new Box;
$box->a[] =& $box->a[0];
$box->a[0] = 9;
echo "box a[1]=" . $box->a[1] . "\n";

class Nest {
    public $n = ['x' => [1]];
}
$nest = new Nest;
$nest->n['x'][] =& $nest->n['x'][0];
$nest->n['x'][0] = 7;
echo "nest n[x][1]=" . $nest->n['x'][1] . "\n";
