--TEST--
Stdlib: preg_replace_callback_array() enum case subject must TypeError (#3568, ext/pcre/php_pcre.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    preg_replace_callback_array(['/x/' => fn(array $m): string => 'y'], E::A);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: preg_replace_callback_array(): Argument #2 ($subject) must be of type array|string, E given
