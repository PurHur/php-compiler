--TEST--
stdlib settype() on readonly property must throw Error (ext/standard/type.c, issue #5041)
--FILE--
<?php
class R5041 {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$o = new R5041();
try {
    settype($o->x, 'string');
    echo "ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo $o->x, "\n";
--EXPECT--
Cannot modify readonly property R5041::$x
1
