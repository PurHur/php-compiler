<?php
// String-key foreach-by-ref RMW must not treat walk index as packed dim (#36397).
$b = ['x' => 1, 'y' => 2];
$a = $b;
foreach ($a as &$w) {
    $w += 1;
}
unset($w);
echo implode(',', $a), '|', implode(',', $b), "\n";

$b2 = ['x' => 1, 'y' => 2];
$a2 = $b2;
foreach ($a2 as &$w2) {
    $w2 = $w2 + 1;
}
unset($w2);
echo implode(',', $a2), '|', implode(',', $b2), "\n";
