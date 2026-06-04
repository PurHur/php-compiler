--TEST--
Generator::current() on yielded enum case — var_export matches Zend (zend_generators.c, #5628)
--FILE--
<?php
enum E: int { case A = 1; }
enum U { case X; }

$backed = (function () {
    yield E::A;
})();
var_export($backed->current());
echo "\n";

$unit = (function () {
    yield U::X;
})();
var_export($unit->current());
echo "\n";

$viaVar = (function () {
    $x = E::A;
    yield $x;
})();
$c = $viaVar->current();
echo ($c === E::A) ? "same\n" : "diff\n";
var_export($c);
echo "\n";
?>
--EXPECT--
\E::A
\U::X
same
\E::A
