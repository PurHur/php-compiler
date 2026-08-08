--TEST--
JIT: touch() via __compiler_touch
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/touch_fixture';
$path = $base . '/marker.txt';
@unlink($path);
if (touch($path)) {
    echo 'create', "\n";
} else {
    echo 'nocreate', "\n";
}
$t = 1000000000;
if (touch($path, $t)) {
    echo 'set', "\n";
} else {
    echo 'noset', "\n";
}
$m = filemtime($path);
if ($m === $t) {
    echo 'mtime', "\n";
} else {
    echo 'badmtime', "\n";
}
$path2 = $base . '/marker2.txt';
@unlink($path2);
$mtime = 1000000100;
$atime = 1000000200;
if (touch($path2, $mtime, $atime)) {
    echo 'atime_set', "\n";
} else {
    echo 'noatime', "\n";
}
$s = stat($path2);
if ($s['mtime'] === $mtime && $s['atime'] === $atime) {
    echo 'atime_ok', "\n";
} else {
    echo 'badatime', "\n";
}
@unlink($path);
@unlink($path2);
--EXPECT--
create
set
mtime
atime_set
atime_ok
