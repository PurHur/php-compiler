--TEST--
stdlib PharData::compressFiles() BadMethodCallException on tar (#21693, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phardata_cfiles_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$tar = $dir . '/pcf.tar';
if (is_file($tar)) {
    unlink($tar);
}
$p = new PharData($tar);
$p['a.txt'] = str_repeat('h', 20);
try {
    $p->compressFiles(Phar::GZ);
    echo "ok\n";
} catch (BadMethodCallException $e) {
    echo 'class=BadMethodCallException', "\n";
    echo 'msg=', (str_contains($e->getMessage(), 'tar archives cannot compress individual files') ? 'tar' : $e->getMessage()), "\n";
}
try {
    $p->compressFiles(0);
    echo "none=ok\n";
} catch (BadMethodCallException $e) {
    echo 'none=', (str_contains($e->getMessage(), 'Unknown compression') ? 'unknown' : $e->getMessage()), "\n";
}
?>
--EXPECT--
class=BadMethodCallException
msg=tar
none=unknown
