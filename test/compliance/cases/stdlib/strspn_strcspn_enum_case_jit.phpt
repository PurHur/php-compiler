--TEST--
stdlib strspn()/strcspn() JIT — TypeError for enum case operands
--FILE--
<?php
enum E: string { case A = 'abc'; }
try {
    $unused = strspn(E::A, 'a');
    echo "uncaught haystack\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $unused = strcspn('a', E::A);
    echo "uncaught mask\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo strspn('abc', 'a'), "\n";
--EXPECT--
strspn(): Argument #1 ($string) must be of type string, E given
strcspn(): Argument #2 ($characters) must be of type string, E given
1
