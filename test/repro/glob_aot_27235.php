<?php
// Repro #27235 — if/else (ternary + nested concat SEGV is a separate thin-AOT bug).
$g = glob('composer.*');
if (is_array($g)) {
    echo 'count='.count($g).' '.implode(',', $g);
} else {
    echo 'NOTARRAY:'.var_export($g, true);
}
echo "\n";
