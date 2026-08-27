<?php
// Repro #35547 / #25240 — continue in try must run finally before leaving iteration.
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
