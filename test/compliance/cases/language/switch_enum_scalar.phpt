--TEST--
Language: switch enum↔scalar case labels must not match via backing compare (#9857, zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; }
$e = E::A;
switch ($e) {
    case 1: echo "int\n"; break;
    default: echo "def\n";
}

enum S: string { case A = 'a'; }
$e = S::A;
switch ($e) {
    case 'a': echo "str\n"; break;
    default: echo "def\n";
}

switch (1) {
    case E::A: echo "rev-int\n"; break;
    default: echo "rev-def\n";
}

switch ('a') {
    case S::A: echo "rev-str\n"; break;
    default: echo "rev-str-def\n";
}

switch (E::A) {
    case E::A: echo "identity\n"; break;
    default: echo "identity-no\n";
}
?>
--EXPECT--
def
def
rev-def
rev-str-def
identity
