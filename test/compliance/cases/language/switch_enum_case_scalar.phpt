--TEST--
Language: switch() enum case label must not match scalar subject (#9857, zend_operators.c, #5819, #5835)
--FILE--
<?php
enum E: int { case A = 1; }
switch (1) {
    case E::A:
        echo "match\n";
        break;
    default:
        echo "no\n";
}

enum S: string { case B = 'x'; }
switch ('x') {
    case S::B:
        echo "str-match\n";
        break;
    default:
        echo "str-no\n";
}

switch (E::A) {
    case E::A:
        echo "identity\n";
        break;
    default:
        echo "identity-no\n";
}

switch (E::A) {
    case 1:
        echo "sym-match\n";
        break;
    default:
        echo "sym-no\n";
}
?>
--EXPECT--
no
str-no
identity
sym-no
