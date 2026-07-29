--TEST--
SPL DirectoryIterator*::__construct Reflection/named directory (#24503)
--FILE--
<?php
$dir = sys_get_temp_dir();
foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $c) {
    $r = new ReflectionMethod($c, '__construct');
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $c, '=', implode(',', $names), "\n";
    $o = new $c(directory: $dir);
    echo $c, '_named=ok', "\n";
    try {
        new $c(path: $dir);
        echo $c, "_path=accepted\n";
    } catch (Error $e) {
        echo $c, '_path=rejected', "\n";
    }
    unset($o);
}
--EXPECT--
DirectoryIterator=directory
DirectoryIterator_named=ok
DirectoryIterator_path=rejected
FilesystemIterator=directory,flags
FilesystemIterator_named=ok
FilesystemIterator_path=rejected
RecursiveDirectoryIterator=directory,flags
RecursiveDirectoryIterator_named=ok
RecursiveDirectoryIterator_path=rejected
