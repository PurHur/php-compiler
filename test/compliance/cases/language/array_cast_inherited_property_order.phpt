--TEST--
Language: (array) object cast — inherited private before child (#23451)
--FILE--
<?php
class P23451 {
    private $p = 1;
    protected $q = 2;
    public $r = 3;
}
class C23451 extends P23451 {
    private $p = 4;
    public $s = 5;
}
foreach ((array)(new C23451) as $k => $v) {
    echo str_replace("\0", '\\0', $k), '=', $v, "\n";
}
--EXPECT--
\0P23451\0p=1
\0*\0q=2
r=3
\0C23451\0p=4
s=5
