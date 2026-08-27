<?php
$out = "";
for ($i = 0; $i < 3; $i++) {
    try {
        try {
            if ($i === 1) {
                continue;
            }
            $out .= "B$i";
        } finally {
            $out .= "I$i";
        }
    } finally {
        $out .= "O$i";
    }
}
echo $out, "\n";
