--TEST--
Language: (void) statement cast accepted on PROFILE=8.5 (#28441, re-#7346)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
try {
    eval('(void) strlen("x");');
    echo "void_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
echo "ok\n";
--EXPECT--
void_ok
ok
