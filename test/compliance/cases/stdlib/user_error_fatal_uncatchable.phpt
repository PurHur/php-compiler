--TEST--
Stdlib: user_error(E_USER_ERROR) is not catchable (#16747)
--FILE--
<?php
try {
    user_error('fatal test', E_USER_ERROR);
    echo "uncaught_path\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
echo "after\n";
--EXPECTF--
PHP Fatal error:  fatal test in %s on line %d
--EXPECT_EXIT--
255
