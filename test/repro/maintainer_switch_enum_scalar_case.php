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
