--TEST--
stdlib strripos() JIT — enum case TypeError (#8959)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'x';
}

foreach (['haystack', 'needle'] as $which) {
    try {
        if ('haystack' === $which) {
            strripos(E::A, 'x');
        } else {
            strripos('x', E::A);
        }
        echo "$which: uncaught\n";
    } catch (TypeError $e) {
        echo $which, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
haystack: strripos(): Argument #1 ($haystack) must be of type string, E given
needle: strripos(): Argument #2 ($needle) must be of type string, E given
