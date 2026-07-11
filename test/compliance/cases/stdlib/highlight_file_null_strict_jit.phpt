--TEST--
stdlib highlight_file() null under strict_types — JIT TypeError (#17174, ext/standard/url.c)
--FILE--
<?php
declare(strict_types=1);

try {
    highlight_file(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
highlight_file(): Argument #1 ($filename) must be of type string, null given
