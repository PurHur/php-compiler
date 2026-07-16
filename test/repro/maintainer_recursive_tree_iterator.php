<?php
/**
 * Issue #6273 — RecursiveTreeIterator ASCII tree over RecursiveDirectoryIterator.
 *
 * Zend: php test/repro/maintainer_recursive_tree_iterator.php
 * VM:   php bin/vm.php test/repro/maintainer_recursive_tree_iterator.php
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/rti_repro_' . uniqid('', true);
mkdir($tmp) || exit("mkdir fail\n");
file_put_contents($tmp . '/a.txt', 'x');
mkdir($tmp . '/sub');
file_put_contents($tmp . '/sub/b.txt', 'y');

$rdi = new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS);
$it = new RecursiveTreeIterator($rdi);
$lines = [];
foreach ($it as $path) {
    $lines[] = str_replace($tmp, '/DIR', (string) $path);
}
sort($lines);
$expect = [
    '  \\-/DIR/sub/b.txt',
    '|-/DIR/a.txt',
    '\\-/DIR/sub',
];
sort($expect);

@unlink($tmp . '/a.txt');
@unlink($tmp . '/sub/b.txt');
@rmdir($tmp . '/sub');
@rmdir($tmp);

if ($lines !== $expect) {
    echo 'fail: ' . json_encode($lines) . "\n";
    exit(1);
}
if (!class_exists('RecursiveTreeIterator', false)) {
    echo "fail: class missing\n";
    exit(1);
}
if (CachingIterator::CATCH_GET_CHILD !== 16) {
    echo "fail: CATCH_GET_CHILD\n";
    exit(1);
}
echo "ok\n";
