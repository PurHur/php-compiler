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
@unlink($path);
--EXPECT--
create
set
mtime
