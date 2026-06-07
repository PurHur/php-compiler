--TEST--
stdlib preg_match()/preg_match_all() JIT — enum case subject TypeError (#7153, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);

enum Color {
    case Red;
}

try {
    preg_match('/red/', Color::Red);
    echo "preg_match uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    preg_match_all('/red/', Color::Red);
    echo "preg_match_all uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_match(): Argument #2 ($subject) must be of type string, Color given
preg_match_all(): Argument #2 ($subject) must be of type string, Color given
