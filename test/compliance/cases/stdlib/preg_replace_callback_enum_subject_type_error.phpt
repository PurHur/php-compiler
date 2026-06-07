--TEST--
Stdlib: preg_replace_callback() enum case subject must TypeError (#7205, ext/pcre/php_pcre.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
function cb(array $m): string {
    return 'y';
}
try {
    preg_replace_callback('/x/', 'cb', E::A);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: preg_replace_callback(): Argument #3 ($subject) must be of type array|string, E given
