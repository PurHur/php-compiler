--TEST--
stdlib spl_autoload_register() — enum case callback TypeError (#6244, ext/spl/php_spl.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    spl_autoload_register(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function my_autoload(string $class): void {
}
spl_autoload_register('my_autoload');
echo "ok\n";
--EXPECT--
spl_autoload_register(): Argument #1 ($callback) must be a valid callback or null, no array or string given
ok
