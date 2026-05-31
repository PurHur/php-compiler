--TEST--
AOT file() reads path into array of lines
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_file_aot_' . getmypid() . '.txt';
$n = file_put_contents($path, "one\ntwo\nthree\n");
$lines = file($path);
echo count($lines), ':', $lines[0], $lines[1];
$trim = file($path, FILE_IGNORE_NEW_LINES);
echo "\n", count($trim), ':', $trim[0], '|', $trim[2], "\n";
$bad = file($path . '_missing_' . getmypid());
echo $bad === false ? 'false' : 'bad', "\n";
unlink($path);
--EXPECT--
3:one
two
3:one|three
false
