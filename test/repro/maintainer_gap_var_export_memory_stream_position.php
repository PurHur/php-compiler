<?php
/** Repro #25084 — var_export(fread()) on php://memory must not re-emit prior fwrite. */
$h = fopen('php://memory', 'r+');
fwrite($h, 'abcdef');
rewind($h);
$a = var_export(fread($h, 3), true);
echo 'pos=', ftell($h), "\n";
$b = var_export(fread($h, 10), true);
echo $a, '|', $b, ' pos2=', ftell($h), "\n";
