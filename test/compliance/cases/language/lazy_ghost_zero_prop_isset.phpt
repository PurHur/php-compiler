--TEST--
Language: newLazyGhost on zero-prop class — isset does not run initializer (#21570)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip ReflectionClass::newLazyGhost requires PHP 8.4+');
}
?>
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function (int $n, string $m): bool {
    echo "WARN\n";
    return true;
});

$rc = new ReflectionClass(stdClass::class);
$initRan = false;
$o = $rc->newLazyGhost(function (stdClass $obj) use (&$initRan): void {
    $initRan = true;
    $obj->x = 1;
});

echo $rc->isUninitializedLazyObject($o) ? "pending\n" : "ready\n";
$isset1 = isset($o->x);
echo "isset=", var_export($isset1, true), "\n";
echo "initRan=", $initRan ? "yes" : "no", "\n";
$val = $o->x;
echo "val=", var_export($val, true), "\n";
echo "initRan2=", $initRan ? "yes" : "no", "\n";

class EmptyC {}
$rcE = new ReflectionClass(EmptyC::class);
$o2 = $rcE->newLazyGhost(function (EmptyC $obj): void {
    $obj->x = 1;
});
echo $rcE->isUninitializedLazyObject($o2) ? "EmptyC-pending\n" : "EmptyC-ready\n";
$isset2 = isset($o2->x);
echo "EmptyC-isset=", var_export($isset2, true), "\n";

class HasProp { public $x; }
$rcH = new ReflectionClass(HasProp::class);
$o3 = $rcH->newLazyGhost(function (HasProp $obj): void {
    echo "INIT\n";
    $obj->x = 1;
});
echo $rcH->isUninitializedLazyObject($o3) ? "HasProp-pending\n" : "HasProp-ready\n";
$isset3 = isset($o3->x);
echo "HasProp-isset=", var_export($isset3, true), "\n";
echo "HasProp-val=", var_export($o3->x, true), "\n";
?>
--EXPECT--
ready
isset=false
initRan=no
WARN
val=NULL
initRan2=no
EmptyC-ready
EmptyC-isset=false
HasProp-pending
INIT
HasProp-isset=true
HasProp-val=1
