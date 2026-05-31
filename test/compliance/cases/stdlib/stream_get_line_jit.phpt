--TEST--
JIT: stream_get_line() via __compiler_stream_get_line (issue #3738)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/stream_get_line_fixture.txt';
$fp = fopen($path, 'r');
echo stream_get_line($fp, 1024, "\n"), "\n";
fclose($fp);
--EXPECT--
hello
