--TEST--
stdlib preg_filter() — enum case $subject must TypeError JIT (#9026, ext/pcre/php_pcre.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

enum Color: string {
    case Red = 'red';
}

try {
    preg_filter('/red/', 'x', Color::Red);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: preg_filter(): Argument #3 ($subject) must be of type array|string, Color given
