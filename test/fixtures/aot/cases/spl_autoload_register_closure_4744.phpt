--TEST--
AOT: spl_autoload_register() closure callback compiles (#4744, ext/spl/php_spl.c)
--FILE--
<?php
spl_autoload_register(function (string $class): void {
    if ($class === 'Autoloaded') {
        class Autoloaded {
            public function id(): int { return 7; }
        }
    }
});
echo (new Autoloaded())->id(), "\n";
--EXPECT--
7
--EXPECT_EXIT--
0
