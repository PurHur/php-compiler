--TEST--
DirectoryIterator family null/empty path ValueError cites $directory (#31512)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo 'DEP:', $m, "\n";

    return true;
});
foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $cls) {
    echo '== ', $cls, " ==\n";
    try {
        new $cls(null);
        echo "uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        new $cls('');
        echo "uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
== DirectoryIterator ==
DEP:DirectoryIterator::__construct(): Passing null to parameter #1 ($directory) of type string is deprecated
ValueError: DirectoryIterator::__construct(): Argument #1 ($directory) cannot be empty
ValueError: DirectoryIterator::__construct(): Argument #1 ($directory) cannot be empty
== FilesystemIterator ==
DEP:FilesystemIterator::__construct(): Passing null to parameter #1 ($directory) of type string is deprecated
ValueError: FilesystemIterator::__construct(): Argument #1 ($directory) cannot be empty
ValueError: FilesystemIterator::__construct(): Argument #1 ($directory) cannot be empty
== RecursiveDirectoryIterator ==
DEP:RecursiveDirectoryIterator::__construct(): Passing null to parameter #1 ($directory) of type string is deprecated
ValueError: RecursiveDirectoryIterator::__construct(): Argument #1 ($directory) cannot be empty
ValueError: RecursiveDirectoryIterator::__construct(): Argument #1 ($directory) cannot be empty
