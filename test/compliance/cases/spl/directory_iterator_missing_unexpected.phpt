--TEST--
DirectoryIterator family missing path UnexpectedValueException (#31506)
--FILE--
<?php
error_reporting(E_ALL);
$path = '/no/such/dir_fixed';
foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $cls) {
    echo '== ', $cls, " ==\n";
    try {
        new $cls($path);
        echo "uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
== DirectoryIterator ==
UnexpectedValueException: DirectoryIterator::__construct(/no/such/dir_fixed): Failed to open directory: No such file or directory
== FilesystemIterator ==
UnexpectedValueException: FilesystemIterator::__construct(/no/such/dir_fixed): Failed to open directory: No such file or directory
== RecursiveDirectoryIterator ==
UnexpectedValueException: RecursiveDirectoryIterator::__construct(/no/such/dir_fixed): Failed to open directory: No such file or directory
