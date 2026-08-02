<?php
/**
 * #26375 — get_debug_type Reflection matches Zend stubs (mixed $value): string.
 */
$r = new ReflectionFunction('get_debug_type');
$p = $r->getParameters()[0];
echo 'param=', $p->getName(), ' type=', $p->getType() ? (string) $p->getType() : '<none>', "\n";
echo 'return=', $r->getReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
echo get_debug_type(value: 1), "\n";
