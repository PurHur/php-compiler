<?php
// #24330 — EXTR_PREFIX_IF_EXISTS: set → prefixed; absent/UNDEF → unprefixed.
// Zend: n=2 a=1 p_a=2 b=3
function repro(): void {
    $a = 1;
    $arr = ['a' => 2, 'b' => 3];
    $n = extract($arr, EXTR_PREFIX_IF_EXISTS, 'p');
    echo "n=$n a=$a p_a=", $p_a ?? 'unset', ' b=', $b ?? 'unset', "\n";
}
repro();
