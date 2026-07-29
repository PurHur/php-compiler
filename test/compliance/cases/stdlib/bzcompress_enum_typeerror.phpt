--TEST--
stdlib bzcompress() — backed enum case TypeError (#3402, ext/bz2/bz2.c, php-src-strict)
--ENV--
PHP_COMPILER_ENABLE_BZ2=1
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'data';
}

try {
    bzcompress(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
bzcompress(): Argument #1 ($source) must be of type string, E given
