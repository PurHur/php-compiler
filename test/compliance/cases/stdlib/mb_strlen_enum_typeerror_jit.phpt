--TEST--
stdlib mb_strlen() JIT — backed enum case TypeError (#5873)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    mb_strlen(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_strlen(): Argument #1 ($string) must be of type string, Es given
