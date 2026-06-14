--TEST--
stdlib php://temp stream — read/write/seek parity (issue #4647, ext/standard/streams.c)
--FILE--
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
fseek($h, 0, SEEK_END);
echo ftell($h), "\n";
fclose($h);
--EXPECT--
buffered
buffered
8
