<?php

declare(strict_types=1);

/**
 * class_parents() autoload flag — issue #5026.
 *
 * php-src: ext/standard/class.c — PHP_FUNCTION(class_parents)
 */

class ParityClassParentsA {}
class ParityClassParentsB extends ParityClassParentsA {}

var_export(class_parents(ParityClassParentsB::class, true));
echo "\n";
var_export(class_parents(ParityClassParentsB::class, false));
echo "\n";

interface ParityClassParentsIface {}
var_export(class_parents(ParityClassParentsIface::class, true));
echo "\n";
