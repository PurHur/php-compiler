<?php
$out = [];
for ($i = 0; $i < 3; $i++) {
    $out[] = str_pad(implode(",", array_map("strval", [1, 2, 3])), 5);
}
echo implode("|", $out), "\n";
