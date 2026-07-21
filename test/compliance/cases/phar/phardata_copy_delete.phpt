--TEST--
stdlib PharData::copy()/delete() on tar archives (#21690, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phardata_copy_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$tar = $dir . '/pcd.tar';
if (is_file($tar)) {
    unlink($tar);
}
$p = new PharData($tar);
$p['a.txt'] = 'hi';
$p->copy('a.txt', 'b.txt');
echo 'copy=', $p['b.txt']->getContent(), "\n";
$p->delete('a.txt');
echo 'del=', isset($p['a.txt']) ? 'still' : 'gone', "\n";
echo 'b=', isset($p['b.txt']) ? 'yes' : 'no', "\n";
try {
    $p->delete('missing.txt');
    echo "miss=ok\n";
} catch (BadMethodCallException $e) {
    echo "miss=BadMethodCallException\n";
}
?>
--EXPECT--
copy=hi
del=gone
b=yes
miss=BadMethodCallException
