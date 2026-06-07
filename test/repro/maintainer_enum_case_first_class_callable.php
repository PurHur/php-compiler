<?php

enum E {
    case A;
    public function f(): string { return 'a'; }
}
$c = E::A->f(...);
echo $c(), "\n";
