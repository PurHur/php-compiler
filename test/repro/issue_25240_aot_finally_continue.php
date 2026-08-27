<?php
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
