<?php
// AOT: break in try must still run finally (re-#25240 / #35547).
$out = '';
for ($i = 0; $i < 3; $i++) {
    try {
        $out .= 'B'.$i;
        if ($i === 1) {
            break;
        }
    } finally {
        $out .= 'F'.$i;
    }
}
echo $out, "\n";
