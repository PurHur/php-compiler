--TEST--
stdlib filesystem permission builtins JIT — backed enum case TypeError (#6079, ext/standard/filestat.c)
--JIT--
--FILE--
<?php
enum E: int { case A = 1; }
foreach (['chown', 'lchown', 'chgrp', 'lchgrp'] as $fn) {
    try {
        $fn('/tmp', E::A);
        echo "$fn uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
try {
    chmod('/tmp', E::A);
    echo "chmod uncaught\n";
} catch (TypeError $e) {
    echo 'chmod: ', $e->getMessage(), "\n";
}
try {
    chown(E::A, 0);
    echo "chown_path uncaught\n";
} catch (TypeError $e) {
    echo 'chown_path: ', $e->getMessage(), "\n";
}
--EXPECT--
chown: chown(): Argument #2 ($user) must be of type string|int, E given
lchown: lchown(): Argument #2 ($user) must be of type string|int, E given
chgrp: chgrp(): Argument #2 ($group) must be of type string|int, E given
lchgrp: lchgrp(): Argument #2 ($group) must be of type string|int, E given
chmod: chmod(): Argument #2 ($permissions) must be of type int, E given
chown_path: chown(): Argument #1 ($filename) must be of type string, E given
