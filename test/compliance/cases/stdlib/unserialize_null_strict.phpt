--TEST--
stdlib unserialize(null) under strict_types throws TypeError (#29765, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    unserialize(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
unserialize(): Argument #1 ($data) must be of type string, null given
