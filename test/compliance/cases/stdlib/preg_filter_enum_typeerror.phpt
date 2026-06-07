--TEST--
Stdlib: preg_filter() enum case subject must TypeError (#7154, ext/pcre/php_pcre.c)
--FILE--
<?php
enum Color: string { case Red = 'red'; }
try {
    $r = preg_filter('/red/', 'x', Color::Red);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: preg_filter(): Argument #3 ($subject) must be of type array|string, Color given
