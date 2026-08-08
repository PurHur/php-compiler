--TEST--
stdlib touch()
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
if (is_file($path)) {
    echo 'exists', "\n";
} else {
    echo 'missing', "\n";
}
// is_file() populated the positive stat cache — clear before timed touch asserts
// (php-src keeps positive hits across touch until clearstatcache, #25853).
clearstatcache(true, $path);
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
clearstatcache(true, $path);
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
exists
set
mtime
atime_set
atime_ok
