<?php
enum E: string { case A = 'x'; }
$e = E::A;
var_dump($e);
echo $e->name, "\n";
echo $e->value, "\n";
