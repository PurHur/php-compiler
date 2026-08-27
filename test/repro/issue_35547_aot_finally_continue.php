<?php
// AOT: continue in try must still run finally (re-#25240 / #35547).
// Break (non-merge leave) remains AOT-wrong — VM matches Zend via beginGotoFinallyUnwind.
$out = '';
for ($i = 0; $i < 3; $i++) {
    try {
        if ($i === 1) {
            continue;
        }
        $out .= 'B'.$i;
    } finally {
        $out .= 'F'.$i;
    }
}
echo $out, "\n";
