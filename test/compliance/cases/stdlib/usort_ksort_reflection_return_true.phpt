--TEST--
stdlib usort/uasort/uksort/ksort/krsort Reflection return true (ext/standard/array.stub.php; #26172)
--FILE--
<?php
foreach (['usort', 'uasort', 'uksort', 'ksort', 'krsort'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '|', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
$a = [3, 1, 2];
echo 'usort_runtime=', var_export(usort($a, static fn ($x, $y) => $x <=> $y), true), "\n";
$b = ['b' => 2, 'a' => 1];
echo 'ksort_runtime=', var_export(ksort($b), true), "\n";
?>
--EXPECT--
usort|true
uasort|true
uksort|true
ksort|true
krsort|true
usort_runtime=true
ksort_runtime=true
