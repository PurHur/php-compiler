<?php

$g = (function () {
    yield 1;
    yield 2;
})();

var_export(iterator_to_array($g, false));
echo "\n";

$pk = false;
$g2 = (function () {
    yield 1;
    yield 2;
})();
var_export(iterator_to_array($g2, $pk));
echo "\n";

$pkInt = 0;
$g3 = (function () {
    yield 1;
    yield 2;
})();
var_export(iterator_to_array($g3, $pkInt));
