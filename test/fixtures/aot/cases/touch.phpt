--TEST--
AOT: touch() creates file and sets mtime via libc utime
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
$mtime = 1000000100;
$atime = 1000000200;
if (touch($path, $mtime, $atime)) {
    echo 'atime_set', "\n";
} else {
    echo 'noatime', "\n";
}
$s = stat($path);
if ($s['mtime'] === $mtime && $s['atime'] === $atime) {
    echo 'atime_ok', "\n";
} else {
    echo 'badatime', "\n";
}
@unlink($path);
--EXPECT--
create
set
mtime
atime_set
atime_ok
