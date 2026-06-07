--TEST--
stdlib dirname()/basename()/realpath()/pathinfo() — backed enum case TypeError (#5944, ext/standard)
--FILE--
<?php
enum Ep: string { case P = '/tmp/foo/bar.php'; }
foreach (['dirname', 'basename', 'realpath', 'pathinfo'] as $fn) {
    try {
        $fn(Ep::P);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
dirname(): Argument #1 ($path) must be of type string, Ep given
basename(): Argument #1 ($path) must be of type string, Ep given
realpath(): Argument #1 ($path) must be of type string, Ep given
pathinfo(): Argument #1 ($path) must be of type string, Ep given
