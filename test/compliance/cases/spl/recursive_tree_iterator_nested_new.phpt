--TEST--
RecursiveTreeIterator nested new RecursiveDirectoryIterator (#19440, #6273)
--FILE--
<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/rti_nested_' . uniqid('', true);
mkdir($tmp);
file_put_contents($tmp . '/a.txt', 'x');
mkdir($tmp . '/sub');
file_put_contents($tmp . '/sub/b.txt', 'y');

$entries = [];
foreach (
    new RecursiveTreeIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS)
    ) as $path => $entry
) {
    $entries[] = basename((string) $entry);
}
sort($entries);
echo json_encode($entries), "\n";

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

@unlink($tmp . '/a.txt');
@unlink($tmp . '/sub/b.txt');
@rmdir($tmp . '/sub');
@rmdir($tmp);
--EXPECT--
["a.txt","b.txt","sub"]
["a.txt","b.txt","sub"]
