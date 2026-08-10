--TEST--
AOT: substr_replace(null $replace) under strict_types TypeError (#29874)
--FILE--
<?php
declare(strict_types=1);
try {
    var_dump(substr_replace('abc', null, 1));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_replace(): Argument #2 ($replace) must be of type array|string, null given
