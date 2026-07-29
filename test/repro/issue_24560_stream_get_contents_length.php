<?php
declare(strict_types=1);

// #24560 — stream_get_contents($length < -1) must throw ValueError (php-src file.c).
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcd');
rewind($f);
try {
    stream_get_contents($f, -2);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
fclose($f);
