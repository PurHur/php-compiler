--TEST--
AOT: ReflectionClass::newLazyGhost property init (#27302)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Lg {
    public int $x;
    function __construct() { $this->x = 7; }
}
$o = (new ReflectionClass(Lg::class))->newLazyGhost(function (object $obj) {
    $obj->__construct();
});
echo $o->x, "\n";
?>
--EXPECT--
7
