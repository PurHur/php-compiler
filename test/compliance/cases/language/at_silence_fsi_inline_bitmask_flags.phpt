--TEST--
FilesystemIterator inline CONST|CONST flags after @mkdir stay int (#24369, Zend/zend_compile.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_atfsi_' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/a.txt', '1');
$it = new FilesystemIterator($dir, FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS);
echo json_encode(iterator_to_array($it, false)), "\n";
// no-@ control
$dir2 = sys_get_temp_dir() . '/phpc_atfsi2_' . getmypid();
mkdir($dir2);
file_put_contents($dir2 . '/b.txt', '1');
$it2 = new FilesystemIterator($dir2, FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS);
echo json_encode(iterator_to_array($it2, false)), "\n";
// precomputed flags after @
$dir3 = sys_get_temp_dir() . '/phpc_atfsi3_' . getmypid();
@mkdir($dir3);
file_put_contents($dir3 . '/c.txt', '1');
$flags = FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS;
$it3 = new FilesystemIterator($dir3, $flags);
echo json_encode(iterator_to_array($it3, false)), "\n";
foreach ([$dir, $dir2, $dir3] as $d) {
    foreach (glob($d . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($d);
}
--EXPECTF--
["%s/a.txt"]
["%s/b.txt"]
["%s/c.txt"]
