--TEST--
Language: exit()/die() — TypeError for non-string non-int status (#4704, zend_builtin_functions.c)
--FILE--
<?php
try {
    exit([]);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    die(new stdClass());
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "ok\n";
--EXPECT--
TypeError:exit(): Argument #1 ($status) must be of type string|int, array given
TypeError:exit(): Argument #1 ($status) must be of type string|int, stdClass given
ok
