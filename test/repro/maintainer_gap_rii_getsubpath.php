<?php
// #24314 — RecursiveIteratorIterator::getSubPath / getSubPathName (php-src-strict)
$d = sys_get_temp_dir().'/rii_gsp_'.getmypid();
@mkdir($d.'/sub', 0777, true);
file_put_contents($d.'/sub/f.txt', 'x');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    echo $it->getSubPath(), "\n";
    echo $it->getSubPathName(), "\n";
    break;
}
