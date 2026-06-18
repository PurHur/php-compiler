--TEST--
iterator_to_array() preserve_keys accepts runtime bool coercion (issue #9151)
--FILE--
<?php
$g = (function () {
    yield 1;
    yield 2;
})();
$a = iterator_to_array($g, false);
var_export($a);
echo "\n";

$pk = false;
$g2 = (function () {
    yield 3;
    yield 4;
})();
$b = iterator_to_array($g2, $pk);
var_export($b);
echo "\n";

$pkInt = 0;
$g3 = (function () {
    yield 5;
    yield 6;
})();
$c = iterator_to_array($g3, $pkInt);
var_export($c);
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 3,
  1 => 4,
)
array (
  0 => 5,
  1 => 6,
)
