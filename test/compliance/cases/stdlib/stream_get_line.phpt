--TEST--
stdlib stream_get_line() reads from stream resources (issue #3738)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/stream_get_line_fixture.txt';
$fp = fopen($path, 'r');
echo stream_get_line($fp, 1024, "\n"), "\n";
fclose($fp);
$fp = fopen($path, 'r');
$all = stream_get_line($fp, 1024);
echo strlen($all), "\n";
fclose($fp);
$fp = fopen($path, 'r');
$skip = stream_get_line($fp, 1024, "\n");
echo stream_get_line($fp, 1024, "\n"), "\n";
$tail = stream_get_line($fp, 1024, "\n");
echo stream_get_line($fp, 1024) === false ? "eof" : "more", "\n";
fclose($fp);
$fp = fopen($path, 'r');
echo stream_get_line($fp, 3, "\n"), "\n";
fclose($fp);
--EXPECT--
hello
11
world
eof
hel
