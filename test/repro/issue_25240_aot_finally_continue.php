<?php
// Repro #35547 / #25240 — break/continue in try must run finally before leaving iteration.
$out = "";
for ($i = 0; $i < 3; $i++) {
    try {
        if ($i === 1) {
            continue;
        }
        $out .= "B$i";
    } finally {
        $out .= "F$i";
    }
}
echo $out, "\n";
$out = "";
for ($i = 0; $i < 3; $i++) {
    try {
        $out .= "B$i";
        if ($i === 1) {
            break;
        }
    } finally {
        $out .= "F$i";
    }
}
echo $out, "\n";
