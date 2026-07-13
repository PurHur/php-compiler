--TEST--
stdlib explode() JIT — null $string TypeError under declare(strict_types=1) (#18600, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

try {
    explode(',', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
explode(): Argument #2 ($string) must be of type string, null given
