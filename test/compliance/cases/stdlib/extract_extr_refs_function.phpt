--TEST--
stdlib extract(EXTR_REFS) aliases into source array in function/closure (#23572)
--FILE--
<?php
function extr_refs_case(): int {
    $arr = ['x' => 1];
    extract($arr, EXTR_REFS);
    $x = 99;
    return $arr['x'];
}
echo extr_refs_case(), "\n";
$arrTop = ['y' => 1];
extract($arrTop, EXTR_REFS);
$y = 5;
echo $arrTop['y'], "\n";
$fn = function (): int {
    $arr = ['z' => 1];
    extract($arr, EXTR_REFS);
    $z = 7;
    return $arr['z'];
};
echo $fn(), "\n";
$arrTop['y'] = 8;
echo $y, "\n";
?>
--EXPECT--
99
5
7
8
