<?php
// Repro #28709 — AOT SplFileObject foreach yields lines (incl. trailing empty after final \n).
file_put_contents('/tmp/sfo.txt', "a\nb\n");
$f = new SplFileObject('/tmp/sfo.txt');
foreach ($f as $line) {
    echo trim($line), ',';
}
echo "\n";
