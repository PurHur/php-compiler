--TEST--
stdlib highlight_string() — null TypeError under declare(strict_types=1) (#18779, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    highlight_string(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
highlight_string(): Argument #1 ($string) must be of type string, null given
