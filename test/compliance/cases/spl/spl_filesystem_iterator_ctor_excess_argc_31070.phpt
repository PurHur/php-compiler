--TEST--
SPL filesystem iterator constructor excess argc (#31070, ext/spl/spl_directory.c)
--FILE--
<?php
foreach ([
    ['GlobIterator', static fn () => new GlobIterator('*', 0, 1)],
    ['RecursiveDirectoryIterator', static fn () => new RecursiveDirectoryIterator('.', 0, 1)],
    ['FilesystemIterator', static fn () => new FilesystemIterator('.', 0, 1)],
    ['DirectoryIterator', static fn () => new DirectoryIterator('.', 1)],
] as [$name, $fn]) {
    try {
        $fn();
        echo "$name ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
GlobIterator GlobIterator::__construct() expects at most 2 arguments, 3 given
RecursiveDirectoryIterator RecursiveDirectoryIterator::__construct() expects at most 2 arguments, 3 given
FilesystemIterator FilesystemIterator::__construct() expects at most 2 arguments, 3 given
DirectoryIterator DirectoryIterator::__construct() expects exactly 1 argument, 2 given
