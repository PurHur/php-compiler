--TEST--
stdlib class_alias() JIT — backed enum case TypeError (#6583)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'a';
}

try {
    class_alias('stdClass', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

--EXPECT--
class_alias(): Argument #2 ($alias) must be of type string, E given
