<?php
/** Repro #21985 — foreach by-ref unset current leaves residual for next by-value foreach. */
$a = [1, 2, 3];
foreach ($a as &$v) {
    if ($v === 2) {
        unset($a[1]);
    }
}
echo json_encode($a), "\n";
foreach ($a as $v) {
}
echo json_encode($a), "\n";
