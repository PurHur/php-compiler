--TEST--
stdlib iterator_to_array() JIT (issue #3179)
--FILE--
<?php
$g = (function () {
    yield 1;
    yield 2;
})();
$a = iterator_to_array($g);
echo count($a), "\n";
echo $a[0], $a[1], "\n";

$assoc = ['x' => 1, 'y' => 2];
$b = iterator_to_array($assoc, true);
echo count($b), "\n";
echo $b['x'], $b['y'], "\n";

$c = iterator_to_array($assoc);
echo count($c), "\n";
echo $c[0], $c[1], "\n";
--EXPECT--
2
12
2
12
2
12
