--TEST--
SplObjectStorage attach/contains/detach/setInfo excess argc (#30954)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass();
foreach ([
    ['attach', static fn ($st, $obj) => $st->attach($obj, null, 'x')],
    ['contains', static fn ($st, $obj) => $st->contains($obj, 1)],
    ['detach', static fn ($st, $obj) => $st->detach($obj, 1)],
] as [$name, $fn]) {
    try {
        $fn($s, $o);
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
$s2 = new SplObjectStorage();
$o2 = new stdClass();
$s2->attach($o2);
$s2->rewind();
try {
    $s2->setInfo('x', 1);
    echo "setInfo COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'setInfo ', $e->getMessage(), "\n";
}
$s2->attach($o2, 'info');
echo 'contains_ok=', $s2->contains($o2) ? '1' : '0', "\n";
$s2->rewind();
$s2->setInfo('y');
echo 'info_ok=', $s2->getInfo(), "\n";
$s2->detach($o2);
echo 'after_detach=', $s2->contains($o2) ? '1' : '0', "\n";
?>
--EXPECT--
attach SplObjectStorage::attach() expects at most 2 arguments, 3 given
contains SplObjectStorage::contains() expects exactly 1 argument, 2 given
detach SplObjectStorage::detach() expects exactly 1 argument, 2 given
setInfo SplObjectStorage::setInfo() expects exactly 1 argument, 2 given
contains_ok=1
info_ok=y
after_detach=0
