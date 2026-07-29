<?php

declare(strict_types=1);

// #24448 — first EOF after a completed line must be false (not NULL); feof after last line.
$f = fopen('php://temp', 'r+');
fwrite($f, "1 2\n");
rewind($f);
for ($i = 0; $i < 3; ++$i) {
    $r = fscanf($f, '%d %d');
    echo 'i=', $i, ' r=';
    var_export($r);
    echo ' feof=', feof($f) ? 'Y' : 'N', "\n";
}
fclose($f);
