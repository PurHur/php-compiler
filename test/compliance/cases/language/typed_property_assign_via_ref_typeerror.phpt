--TEST--
Typed property assign-via-ref TypeError uses "reference held by" + ?int (#25622)
--FILE--
<?php
// Trailing echo in try avoids a pre-existing CFG/slot alias bug with consecutive
// bare try { $r = ... } blocks after &$obj->prop (master also misbinds $r).
class A
{
    public int $x = 1;
    public ?int $y = 1;
}
class B
{
    public static int $s = 1;
}
$a = new A();
$r = &$a->x;
try {
    $r = 'hi';
    echo "int via-ref: no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $a->x = 'hi';
    echo "int direct: no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$r2 = &$a->y;
try {
    $r2 = 'hi';
    echo "nullable via-ref: no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $a->y = 'hi';
    echo "nullable direct: no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$rs = &B::$s;
try {
    $rs = 'hi';
    echo "static via-ref: no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    B::$s = 'hi';
    echo "static direct: no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Cannot assign string to reference held by property A::$x of type int
TypeError: Cannot assign string to property A::$x of type int
TypeError: Cannot assign string to reference held by property A::$y of type ?int
TypeError: Cannot assign string to property A::$y of type ?int
TypeError: Cannot assign string to reference held by property B::$s of type int
TypeError: Cannot assign string to property B::$s of type int
