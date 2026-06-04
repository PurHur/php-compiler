--TEST--
Language: match() enum case arm must not match scalar subject (zend_operators.c, #5820)
--FILE--
<?php
enum E: int { case A = 1; }
echo match (1) {
    E::A => "match",
    default => "no",
}, "\n";

enum S: string { case B = 'x'; }
echo match ('x') {
    S::B => "str-match",
    default => "str-no",
}, "\n";

echo match (E::A) {
    E::A => "identity",
    default => "identity-no",
}, "\n";
?>
--EXPECT--
no
str-no
identity
