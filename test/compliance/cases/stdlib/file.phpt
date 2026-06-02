--TEST--
stdlib file() reads path into array of lines
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_file_test_' . getmypid() . '.txt';
$n = file_put_contents($path, "a\nb\nc\n");
$lines = file($path);
echo count($lines), ':', $lines[0], $lines[1], $lines[2];
unlink($path);

$path = sys_get_temp_dir() . '/phpc_file_flags_' . getmypid() . '.txt';
$n = file_put_contents($path, "x\n\ny\n");
$noNl = file($path, FILE_IGNORE_NEW_LINES);
$skip = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo "\n", count($noNl), ':', $noNl[0], '|', $noNl[1], '|', $noNl[2], "\n";
echo count($skip), ':', $skip[0], '|', $skip[1], "\n";
$bad = file($path . '_missing_' . getmypid());
echo $bad === false ? 'false' : 'bad', "\n";
unlink($path);
--EXPECT--
3:a
b
c

3:x||y
2:x|y
false
