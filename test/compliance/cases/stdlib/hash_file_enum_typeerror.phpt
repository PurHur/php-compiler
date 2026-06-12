--TEST--
stdlib hash_file() — enum case filename TypeError (#3221, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'x';
}

try {
    hash_file('md5', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hash_file(): Argument #2 ($filename) must be of type string, E given
