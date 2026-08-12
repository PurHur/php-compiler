--TEST--
stdlib empty path ValueError batch2 — Path cannot be empty (#30464, ext/standard/file.c, dir.c)
--FILE--
<?php
foreach ([
    'file_get_contents' => static fn () => file_get_contents(''),
    'file_put_contents' => static fn () => file_put_contents('', 'x'),
    'hash_file' => static fn () => hash_file('md5', ''),
] as $fn => $call) {
    try {
        $call();
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
try {
    scandir('');
    echo "scandir: miss\n";
} catch (ValueError $e) {
    echo 'scandir:', $e->getMessage(), "\n";
}
?>
--EXPECT--
file_get_contents:Path cannot be empty
file_put_contents:Path cannot be empty
hash_file:Path cannot be empty
scandir:scandir(): Argument #1 ($directory) cannot be empty
