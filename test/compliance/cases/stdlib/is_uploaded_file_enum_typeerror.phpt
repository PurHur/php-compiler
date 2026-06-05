--TEST--
stdlib is_uploaded_file() — backed enum case TypeError (#6279, ext/standard/basic_functions.c)
--FILE--
<?php
enum E: string { case A = '/tmp/x'; }
try {
    is_uploaded_file(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
is_uploaded_file(): Argument #1 ($filename) must be of type string, E given
