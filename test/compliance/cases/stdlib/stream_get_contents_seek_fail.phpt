--TEST--
stdlib stream_get_contents() bad offset returns false + Failed to seek warning (#21986, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcdef');
rewind($f);
error_clear_last();
$r = @stream_get_contents($f, 1, 100);
$last = error_get_last();
var_dump($r);
echo is_array($last) && str_contains((string) $last['message'], 'Failed to seek to position 100')
    ? "warn_ok\n"
    : "warn_bad\n";
rewind($f);
$full = stream_get_contents($f);
rewind($f);
$after = stream_get_contents($f, -1, 2);
var_export($full);
echo "\n";
var_export($after);
echo "\n";
var_dump(fseek($f, 100));
fclose($f);
--EXPECT--
bool(false)
warn_ok
'abcdef'
'cdef'
int(-1)
