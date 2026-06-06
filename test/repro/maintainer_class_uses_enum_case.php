<?php

/**
 * class_uses() on enum case — issue #6621.
 *
 * php-src: ext/standard/spl_functions.c — PHP_FUNCTION(class_uses)
 */
enum MaintainerClassUsesEnum6621: string
{
    case A = 'a';
    case B = 'b';
}

var_export(class_uses(MaintainerClassUsesEnum6621::A));
echo "\n";
var_export(class_uses(MaintainerClassUsesEnum6621::B));
echo "\n";
