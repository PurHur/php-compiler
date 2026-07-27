<?php
// Issue #23841: ++ on loop counters must not false-positive when values collide with handle ids.
$fh = fopen('php://memory', 'r+');
$acc = 0;
for ($i = 0; $i < 5; ++$i) {
    ++$acc;
}
echo $acc, "\n";
fclose($fh);
