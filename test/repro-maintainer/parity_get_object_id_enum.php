<?php

enum E: int
{
    case A = 1;
    case B = 2;
}

$id1 = get_object_id(E::A);
$id2 = get_object_id(E::A);
$id3 = get_object_id(E::B);
var_export($id1 === $id2);
echo "\n";
var_export($id1 !== $id3);
echo "\n";
