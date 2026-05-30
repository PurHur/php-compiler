--TEST--
Language: backed enum declarations — string and int scalar types (#3706, Zend zend_enum.c)
--FILE--
<?php
enum E: string {
    case X = 'a';
}
echo E::X->name;
echo "\n";

enum I: int {
    case A = 1;
    case B = 2;
}
echo I::A->name;
echo "\n";
echo I::A->value;
echo "\n";
echo I::B->value;
--EXPECT--
X
A
1
2
