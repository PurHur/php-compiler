--TEST--
AOT str_starts_with() — enum haystack operand TypeError (#6033)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case A = 'ab';
}

try {
    str_starts_with(Es::A, 'a');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
str_starts_with(): Argument #1 ($haystack) must be of type string, Es given
