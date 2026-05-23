--TEST--
stdlib copy()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/copy_fixture';
$from = $base . '/source.txt';
$to = $base . '/dest.txt';
@unlink($to);
$n = file_put_contents($from, 'hello');
if (copy($from, $to)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (is_file($from)) {
    echo 'src', "\n";
} else {
    echo 'nosrc', "\n";
}
if (is_file($to)) {
    echo file_get_contents($to), "\n";
} else {
    echo 'nodest', "\n";
}
if (copy($from . '_missing', $to)) {
    echo 'bad', "\n";
} else {
    echo 'nogone', "\n";
}
@unlink($to);
--EXPECT--
ok
src
hello
nogone
