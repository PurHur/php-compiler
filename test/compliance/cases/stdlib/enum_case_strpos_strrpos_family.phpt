--TEST--
stdlib strpos/strrpos/strripos — enum haystack TypeError (#8961, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'abc';
}

foreach (['strpos', 'strrpos', 'strripos'] as $fn) {
    try {
        $fn(E::A, 'a');
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
    }
}
?>
--EXPECT--
strpos: TypeError
strrpos: TypeError
strripos: TypeError
