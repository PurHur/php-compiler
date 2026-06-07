--TEST--
stdlib explode/strpos/substr/preg_match/strlen/htmlspecialchars — enum case TypeError (#5524, ext/standard/string.c, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'x';
}

foreach (['explode', 'strpos', 'substr', 'preg_match', 'strlen', 'htmlspecialchars'] as $fn) {
    try {
        if ('explode' === $fn) {
            explode('.', E::A);
        } elseif ('strpos' === $fn) {
            strpos(E::A, 'x');
        } elseif ('substr' === $fn) {
            substr(E::A, 0);
        } elseif ('preg_match' === $fn) {
            preg_match('/x/', E::A);
        } elseif ('strlen' === $fn) {
            strlen(E::A);
        } else {
            htmlspecialchars(E::A);
        }
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
explode: explode(): Argument #2 ($string) must be of type string, E given
strpos: strpos(): Argument #1 ($haystack) must be of type string, E given
substr: substr(): Argument #1 ($string) must be of type string, E given
preg_match: preg_match(): Argument #2 ($subject) must be of type string, E given
strlen: strlen(): Argument #1 ($string) must be of type string, E given
htmlspecialchars: htmlspecialchars(): Argument #1 ($string) must be of type string, E given
