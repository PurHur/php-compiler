--TEST--
stdlib RecursiveTreeIterator ASCII tree over RecursiveDirectoryIterator (#6273)
--FILE--
<?php
$tmp = sys_get_temp_dir() . '/phpc_rti_' . uniqid('', true);
mkdir($tmp);
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
// Order of siblings is filesystem-dependent; compare as a set of prefixed paths.
$expect = [
    '  \\-/DIR/sub/b.txt',
    '|-/DIR/a.txt',
    '\\-/DIR/sub',
];
sort($expect);
echo $lines === $expect ? "ok\n" : ('fail: ' . json_encode($lines) . "\n");

echo class_exists('RecursiveTreeIterator') ? "class-ok\n" : "class-missing\n";
echo CachingIterator::CATCH_GET_CHILD === 16 ? "catch-ok\n" : "catch-bad\n";

@unlink($tmp . '/a.txt');
@unlink($tmp . '/sub/b.txt');
@rmdir($tmp . '/sub');
@rmdir($tmp);
?>
--EXPECT--
ok
class-ok
catch-ok
