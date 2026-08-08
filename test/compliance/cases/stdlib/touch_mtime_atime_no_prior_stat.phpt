--TEST--
stdlib touch() 2-arg/3-arg mtime/atime without prior stat (#28995)
--FILE--
<?php
// No prior filemtime/is_file/stat — times must be visible immediately (php-src-strict).
$p = tempnam(sys_get_temp_dir(), 'phpc_touch_mtime_');
$mtime = 1600000000;
$atime = 1599999900;
if (!touch($p, $mtime, $atime)) {
    echo "fail3\n";
    @unlink($p);
    return;
}
echo filemtime($p) === $mtime ? "mtime3\n" : "badmtime3\n";
echo fileatime($p) === $atime ? "atime3\n" : "badatime3\n";
@unlink($p);

$p = tempnam(sys_get_temp_dir(), 'phpc_touch_mtime2_');
if (!touch($p, $mtime)) {
    echo "fail2\n";
    @unlink($p);
    return;
}
echo filemtime($p) === $mtime ? "mtime2\n" : "badmtime2\n";
echo fileatime($p) === $mtime ? "atime2\n" : "badatime2\n";
@unlink($p);
--EXPECT--
mtime3
atime3
mtime2
atime2
