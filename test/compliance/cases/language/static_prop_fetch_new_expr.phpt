--TEST--
Language: static property fetch on (new Class()) expression (#5477, zend_execute.c)
--FILE--
<?php
class C {
    public static int $x = 1;
}
echo (new C)::$x, "\n";

echo (new class {
    public static int $x = 2;
})::$x, "\n";

$c = new C;
echo $c::$x, "\n";

$obj = new class {
    public static int $y = 3;
};
$obj::$y = 4;
echo $obj::$y, "\n";
--EXPECT--
1
2
1
4
