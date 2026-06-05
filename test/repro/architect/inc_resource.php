<?php
$r = fopen('php://memory', 'r+');
try {
    $r++;
    echo "no-error\n";
} catch (TypeError $e) {
    echo 'TypeError: '.$e->getMessage()."\n";
}

$r2 = fopen('php://memory', 'r+');
fclose($r2);
try {
    ++$r2;
} catch (TypeError $e) {
    echo 'TypeError: '.$e->getMessage()."\n";
}

$r3 = fopen('php://memory', 'r+');
try {
    --$r3;
} catch (TypeError $e) {
    echo 'TypeError: '.$e->getMessage()."\n";
}
