--TEST--
RecursiveIteratorIterator::getSubPath/getSubPathName over RDI (#24314, ext/spl/spl_iterators.c)
--FILE--
<?php
$d = sys_get_temp_dir().'/rii_gsp_comp_'.getmypid();
@mkdir($d.'/sub', 0777, true);
file_put_contents($d.'/sub/f.txt', 'x');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    echo $it->getSubPath(), "\n";
    echo $it->getSubPathName(), "\n";
    echo $it->getSubPathname(), "\n";
    break;
}
?>
--EXPECT--
sub
sub/f.txt
sub/f.txt
