<?php
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
