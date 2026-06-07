--TEST--
stdlib file()/readfile()/scandir()/opendir() — enum path operand TypeError (#7233, ext/standard/file.c, dir.c)
--FILE--
<?php
enum E: string { case A = '/etc/hosts'; }
foreach (['file', 'readfile', 'scandir', 'opendir'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
file(): Argument #1 ($filename) must be of type string, E given
readfile(): Argument #1 ($filename) must be of type string, E given
scandir(): Argument #1 ($directory) must be of type string, E given
opendir(): Argument #1 ($directory) must be of type string, E given
