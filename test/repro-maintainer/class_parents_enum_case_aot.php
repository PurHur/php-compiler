<?php

enum AotClassParentsEnum6336
{
    case A;
    case B;
}

$p1 = class_parents(AotClassParentsEnum6336::A);
$p2 = class_parents(AotClassParentsEnum6336::B);
echo is_array($p1) && 0 === count($p1) ? '1' : '0';
echo is_array($p2) && 0 === count($p2) ? '1' : '0';
