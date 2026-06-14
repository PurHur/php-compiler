<?php

$h = fopen('php://input', 'r');
var_dump($h !== false);
if ($h !== false) {
    echo fread($h, 100), "\n";
    fclose($h);
}

$h2 = fopen('php://output', 'w');
var_dump($h2 !== false);
if ($h2 !== false) {
    fwrite($h2, "out\n");
    fclose($h2);
}
