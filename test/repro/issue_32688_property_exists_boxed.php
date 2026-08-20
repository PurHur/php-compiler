<?php
declare(strict_types=1);
/**
 * AOT: property_exists() on a boxed instance must not SIGABRT (#32688).
 * php-src: ext/standard/basic_functions.c PHP_FUNCTION(property_exists)
 */
class C {
    public $x = 1;
}
$c = new C;
var_dump(property_exists($c, 'x'));
$o = new stdClass;
$o->x = 1;
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'y') ? '1' : '0';
echo "\n";
