--TEST--
stdlib trigger_error() — enum case error_level TypeError (#9086, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

try {
    trigger_error('msg', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
trigger_error(): Argument #2 ($error_level) must be of type int, E given
