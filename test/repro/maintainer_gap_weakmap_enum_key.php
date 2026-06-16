<?php
/**
 * Repro for #8949 — WeakMap enum case object keys (Zend/zend_weakrefs.c).
 */
enum E: int { case A = 1; }

$map = new WeakMap();
$map[E::A] = 42;
echo 'set ok value=' . $map[E::A] . "\n";
echo isset($map[E::A]) ? "isset true\n" : "isset false\n";
