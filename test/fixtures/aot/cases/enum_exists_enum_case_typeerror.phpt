--TEST--
AOT: enum_exists() enum case TypeError (#6561)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    enum_exists(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
enum_exists(): Argument #1 ($enum) must be of type string, E given
