<?php
/**
 * #33959 — AOT serialize(stdClass) must not SIGSEGV; match Zend wire.
 * php-src: ext/standard/var.c php_var_serialize object branch
 */
echo serialize(new stdClass), "\n";
$o = new stdClass;
$o->x = 2;
$o->f = 1.5;
$o->b = true;
echo serialize($o), "\n";
echo serialize((object) ['a' => 1]), "\n";
