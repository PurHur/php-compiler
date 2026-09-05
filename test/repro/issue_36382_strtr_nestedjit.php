<?php
/**
 * #36382 — AOT strtr() three-arg must translate runtime subjects (NestedJIT helper).
 * Nyholm ServerRequestCreator::getHeadersFromServer uses
 * strtr(strtolower(substr($key, 5)), '_', '-').
 *
 * @differential-repeat: 3 heap SEGV was intermittent on prior VmString cross-call helper
 */
$x = substr('xa_b_c', 1);
echo strtr($x, '_', '-'), "\n";
$l = strtolower(substr('HTTP_HOST', 5));
echo strtr($l, '_', '-'), "\n";
$l2 = strtolower(substr('HTTP_X_FOO_BAR', 5));
echo strtr($l2, '_', '-'), "\n";
echo strtr('baab', 'ab', '12'), "\n";
