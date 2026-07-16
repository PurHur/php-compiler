--TEST--
Nested new ctor args with ClassConstFetch — RII mode + inner SKIP_DOTS (#19439)
--FILE--
<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/nested_new_rii_' . uniqid('', true);
mkdir($tmp);
file_put_contents($tmp . '/a.txt', 'x');
mkdir($tmp . '/sub');
file_put_contents($tmp . '/sub/b.txt', 'y');

$names = [];
foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $f
) {
    $names[] = $f->getFilename();
}
sort($names);
echo json_encode($names), "\n";

$names2 = [];
foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        1
    ) as $f
) {
    $names2[] = $f->getFilename();
}
sort($names2);
echo json_encode($names2), "\n";

@unlink($tmp . '/a.txt');
@unlink($tmp . '/sub/b.txt');
@rmdir($tmp . '/sub');
@rmdir($tmp);
--EXPECT--
["a.txt","b.txt","sub"]
["a.txt","b.txt","sub"]
