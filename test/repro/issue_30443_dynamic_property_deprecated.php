<?php
/**
 * #30443 — dynamic property E_DEPRECATED must fire without explicit PHP_COMPILER_PROFILE env var.
 *
 * Zend 8.2+ emits E_DEPRECATED for undeclared property writes on classes without
 * #[AllowDynamicProperties]. The compiler's default error_reporting excluded E_DEPRECATED
 * because version_compare('8.4.0-dev', '8.4.0', '>=') is false, so the startup mask
 * fell through to E_ALL & ~E_DEPRECATED.
 *
 * Expected (no env var): "Deprecated: Creation of dynamic property A::$x is deprecated"
 *
 * php-src: Zend/zend_object_handlers.c — zend_std_write_property
 */

class A {}
$a = new A();
$a->x = 1;
echo $a->x, "\n";

// AllowDynamicProperties suppresses the deprecation.
#[\AllowDynamicProperties]
class B {}
$b = new B();
$b->y = 2;
echo $b->y, "\n";

// stdClass never warns.
$o = new stdClass();
$o->z = 3;
echo $o->z, "\n";
