--TEST--
stdlib link/readlink/lstat/linkinfo — enum path catchable errors (#6266, ext/standard/link.c)
--FILE--
<?php
enum E: string { case A = '/tmp/x'; }
foreach (['link', 'readlink', 'lstat', 'linkinfo'] as $fn) {
    try {
        $fn(E::A);
        echo $fn, " ok\n";
    } catch (Throwable $t) {
        echo $fn, ' ', $t::class, ': ', $t->getMessage(), "\n";
    }
}
--EXPECT--
link ArgumentCountError: link() expects exactly 2 arguments, 1 given
readlink TypeError: readlink(): Argument #1 ($path) must be of type string, E given
lstat TypeError: lstat(): Argument #1 ($filename) must be of type string, E given
linkinfo TypeError: linkinfo(): Argument #1 ($path) must be of type string, E given
