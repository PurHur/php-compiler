--TEST--
stdlib count_chars() JIT — backed string enum case TypeError (#6032)
--FILE--
<?php
enum ES: string { case X = 'x'; }
try {
    count_chars(ES::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
count_chars(): Argument #1 ($string) must be of type string, ES given
