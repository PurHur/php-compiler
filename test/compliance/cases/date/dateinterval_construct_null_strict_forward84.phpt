--TEST--
date DateInterval::__construct(null) TypeError under strict_types on PROFILE=8.4 (#29828)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
try {
    new DateInterval(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
DateInterval::__construct(): Argument #1 ($duration) must be of type string, null given
