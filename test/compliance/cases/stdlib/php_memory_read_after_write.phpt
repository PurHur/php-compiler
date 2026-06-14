--TEST--
stdlib php://memory read-after-write via fread/fgets/stream_get_line (issue #4643)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, 'data');
rewind($h);
echo fread($h, 10), "\n";
rewind($h);
echo stream_get_line($h, 10), "\n";
rewind($h);
echo fgets($h), "\n";
rewind($h);
fwrite($h, "line1\nline2");
rewind($h);
echo stream_get_line($h, 1024, "\n"), "\n";
echo stream_get_line($h, 1024, "\n"), "\n";
echo stream_get_line($h, 1024) === false ? "eof" : "more", "\n";
fclose($h);
--EXPECT--
data
data
data

line1
line2
eof
