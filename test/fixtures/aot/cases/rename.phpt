--TEST--
AOT: rename() moves file via libc rename(2)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/rename_fixture';
$from = $base . '/from.txt';
$to = $base . '/to.txt';
@unlink($to);
$n = file_put_contents($from, 'x');
if (rename($from, $to)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (is_file($from)) {
    echo 'old', "\n";
} else {
    echo 'moved', "\n";
}
if (is_file($to)) {
    echo 'new', "\n";
} else {
    echo 'nonew', "\n";
}
@unlink($to);
--EXPECT--
ok
moved
new
