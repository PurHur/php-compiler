--TEST--
stdlib escapeshellarg() — backed enum case TypeError (#5870, ext/standard/escapeshellarg.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }
try {
    var_export(escapeshellarg(E::A));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
escapeshellarg(): Argument #1 ($arg) must be of type string, E given
