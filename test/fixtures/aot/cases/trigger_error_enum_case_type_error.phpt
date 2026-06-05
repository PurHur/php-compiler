--TEST--
AOT trigger_error() — enum case message TypeError (#6184)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'msg'; }

try {
    trigger_error(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
trigger_error(): Argument #1 ($message) must be of type string, E given
