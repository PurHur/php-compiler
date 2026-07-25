--TEST--
stdlib stream_get_contents() offset < -1 keeps current pos (no false) (#23190, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcdef');
// At EOF: negative offset ≠ seek → empty string (not false).
var_export(stream_get_contents($f, 2, -2));
echo "\n";
var_export(stream_get_contents($f, 2, -7));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, -1, -5));
echo "\n";
// Past-EOF positive seek still fails (#21986).
error_clear_last();
$bad = @stream_get_contents($f, 1, 100);
$last = error_get_last();
var_dump($bad);
echo is_array($last) && str_contains((string) $last['message'], 'Failed to seek to position 100')
    ? "warn_ok\n"
    : "warn_bad\n";
fclose($f);

$path = sys_get_temp_dir() . '/phpc_stream_get_contents_neg_offset.txt';
file_put_contents($path, 'abcdef');
$f = fopen($path, 'r');
fseek($f, 6);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
fclose($f);
@unlink($path);
--EXPECT--
''
''
'ab'
'abcdef'
bool(false)
warn_ok
''
'ab'
