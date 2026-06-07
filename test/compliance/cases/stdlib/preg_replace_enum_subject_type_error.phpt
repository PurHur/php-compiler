--TEST--
Stdlib: preg_replace() enum case subject must TypeError (#7453, ext/pcre/php_pcre.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    $r = preg_replace('/x/', 'y', E::A);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: preg_replace(): Argument #3 ($subject) must be of type array|string, E given
