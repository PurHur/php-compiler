--TEST--
stdlib filestat metadata JIT — backed enum case TypeError (#6552, ext/standard/filestat.c)
--FILE--
<?php
enum E: string { case A = 'x'; }

$checks = [
    'filetype',
    'fileperms',
    'fileowner',
    'filegroup',
    'fileinode',
    'fileatime',
    'filectime',
    'filemtime',
];

foreach ($checks as $fn) {
    try {
        $fn(E::A);
        echo "$fn uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
filetype: filetype(): Argument #1 ($filename) must be of type string, E given
fileperms: fileperms(): Argument #1 ($filename) must be of type string, E given
fileowner: fileowner(): Argument #1 ($filename) must be of type string, E given
filegroup: filegroup(): Argument #1 ($filename) must be of type string, E given
fileinode: fileinode(): Argument #1 ($filename) must be of type string, E given
fileatime: fileatime(): Argument #1 ($filename) must be of type string, E given
filectime: filectime(): Argument #1 ($filename) must be of type string, E given
filemtime: filemtime(): Argument #1 ($filename) must be of type string, E given
