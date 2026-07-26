<?php
// #23572 — extract(EXTR_REFS) in function scope must alias into source array
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
