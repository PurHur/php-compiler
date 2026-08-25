<?php
/**
 * #34628 — AOT GlobIterator foreach must match Zend (NestedJIT \glob leaf).
 *
 * Fixture dir under sys_get_temp_dir(); prints match count then basenames sorted.
 */
$dir = sys_get_temp_dir() . '/phpc_gi_34628_' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/a.txt', 'x');
file_put_contents($dir . '/b.txt', 'y');

$n = 0;
$names = [];
foreach (new GlobIterator($dir . '/*.txt') as $f) {
    $n++;
    $names[] = $f->getFilename();
}
sort($names);
echo $n, "\n";
echo implode(',', $names), "\n";

$empty = 0;
foreach (new GlobIterator($dir . '/*.nomatch') as $f) {
    $empty++;
}
echo $empty, "\n";

@unlink($dir . '/a.txt');
@unlink($dir . '/b.txt');
@rmdir($dir);
