--TEST--
stdlib pclose()/popen() — enum case TypeError (#6211, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    pclose(E::A);
    echo "pclose-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    popen(E::A, 'r');
    echo "popen-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
pclose(): Argument #1 ($stream) must be of type resource, E given
popen(): Argument #1 ($command) must be of type string, E given
