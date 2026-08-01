<?php
/**
 * Repro for #26607 — DNF subset arm must be a compile fatal (php-src-strict).
 * Zend: Type A&B&C is redundant as it is more restrictive than type A&B
 */
interface A {}
interface B {}
interface C {}
function f((A&B)|(A&B&C) $x) {}
echo "ok\n";
