<?php

$h = fopen('php://temp', 'r+');
if (false === $h) {
    echo "open_fail\n";
    exit(1);
}
fwrite($h, 'buffered');
rewind($h);
echo fread($h, 20), "\n";
fseek($h, 0);
echo stream_get_contents($h), "\n";
