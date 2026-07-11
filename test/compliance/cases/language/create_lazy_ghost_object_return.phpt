--TEST--
Language: createLazyGhost() ghost initializer object return ignored (#12309)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip createLazyGhost requires PHP 8.4+');
}
?>
--FILE--
<?php
class C {
    public int $v = 0;
}
$ghost = createLazyGhost(C::class, function (C $o) {
    $o->v = 42;
    return $o;
});
echo $ghost->v, "\n";
?>
--EXPECT--
42
