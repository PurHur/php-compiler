--TEST--
stdlib clearstatcache() JIT — backed enum case TypeError (#6262, ext/standard/filestat.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    clearstatcache(true, E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
clearstatcache(): Argument #2 ($filename) must be of type string, E given
