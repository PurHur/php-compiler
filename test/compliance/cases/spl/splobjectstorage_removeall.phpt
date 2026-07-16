--TEST--
SPL object storage removeAll/removeAllExcept (ext/spl/spl_observer.c; #19763)
--FILE--
<?php
$a = new stdClass();
$b = new stdClass();
$c = new stdClass();
$o = new SplObjectStorage();
$o->attach($a, 'A');
$o->attach($b, 'B');
$o->attach($c, 'C');
$o2 = new SplObjectStorage();
$o2->attach($a);
$o2->attach($c);
$o->removeAll($o2);
echo $o->count(), ',', var_export($o->contains($b), true), ',', var_export($o->contains($a), true), "\n";

$o3 = new SplObjectStorage();
$o3->attach($a, 'A');
$o3->attach($b, 'B');
$o3->attach($c, 'C');
$keep = new SplObjectStorage();
$keep->attach($b);
$keep->attach($c);
$o3->removeAllExcept($keep);
echo $o3->count(), ',', var_export($o3->contains($a), true), ',', var_export($o3->contains($b), true), "\n";

try {
    $o->removeAll(new stdClass());
    echo "type_ok\n";
} catch (TypeError $e) {
    echo "type:", $e->getMessage(), "\n";
}
?>
--EXPECT--
1,true,false
2,false,true
type:SplObjectStorage::removeAll(): Argument #1 ($storage) must be of type SplObjectStorage, stdClass given
