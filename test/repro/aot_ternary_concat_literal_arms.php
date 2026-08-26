<?php
// #35095 — AOT drops LHS / SIGSEGVs on ?: literal arms when merge CONCATs.
$cases = [];

ob_start();
echo "ok" . "|" . (json_last_error() === JSON_ERROR_DEPTH ? "depth" : "other");
$cases['chained'] = ob_get_clean();

$x = strlen("abc");
$b = $x === 3 ? "yes" : "no";
ob_start();
echo "ok|" . $b;
$cases['assign'] = ob_get_clean();

ob_start();
echo "ok|" . (json_last_error() === 0 ? "yes" : "no");
$cases['single'] = ob_get_clean();

foreach ($cases as $name => $out) {
    echo $name, "=", $out, "\n";
}
