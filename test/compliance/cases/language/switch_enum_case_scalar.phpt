--TEST--
Language: switch() enum case label must not match scalar subject (zend_operators.c, #5819)
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
?>
--EXPECT--
no
str-no
