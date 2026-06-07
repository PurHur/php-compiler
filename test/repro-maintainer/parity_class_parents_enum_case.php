<?php

/**
 * class_parents() on enum case — issue #6336.
 *
 * php-src: ext/standard/class.c — PHP_FUNCTION(class_parents)
 */
enum ParityClassParentsEnum6336
{
    case A;
    case B;
}

var_export(class_parents(ParityClassParentsEnum6336::A));
echo "\n";
var_export(class_parents(ParityClassParentsEnum6336::B));
echo "\n";
