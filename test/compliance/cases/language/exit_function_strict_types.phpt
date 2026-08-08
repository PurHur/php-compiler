--TEST--
Language: exit() strict_types rejects bool status (#6975, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    exit(true);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
echo "ok\n";
--EXPECT--
TypeError:exit(): Argument #1 ($status) must be of type string|int, true given
ok
