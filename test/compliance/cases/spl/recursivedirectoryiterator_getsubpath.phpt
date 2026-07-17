--TEST--
RecursiveDirectoryIterator getSubPath/getSubPathname nested (#20091, ext/spl/spl_directory.c)
--FILE--
<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/phpc_rdi_sub_' . uniqid('', true);
mkdir($root);
$nested = $root . '/nested';
mkdir($nested);
$deep = $nested . '/deep';
mkdir($deep);
file_put_contents($root . '/top.txt', 't');
file_put_contents($nested . '/a.txt', 'a');
file_put_contents($deep . '/b.txt', 'b');

$it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$rii = new RecursiveIteratorIterator($it);
$out = [];
foreach ($rii as $path => $info) {
    $inner = $rii->getInnerIterator();
    $base = basename((string) $path);
    $out[$base] = $inner->getSubPath() . '|' . $inner->getSubPathname();
}
ksort($out);
foreach ($out as $base => $v) {
    echo $base, '=', $v, "\n";
}

@unlink($deep . '/b.txt');
@rmdir($deep);
@unlink($nested . '/a.txt');
@rmdir($nested);
@unlink($root . '/top.txt');
@rmdir($root);
?>
--EXPECT--
a.txt=nested|nested/a.txt
b.txt=nested/deep|nested/deep/b.txt
top.txt=|top.txt
