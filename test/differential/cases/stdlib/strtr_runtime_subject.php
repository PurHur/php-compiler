<?php
/**
 * Three-arg strtr on runtime subjects (NestedJIT helper; Nyholm header rename).
 * @differential-repeat: 3
 */
$x = substr('xa_b_c', 1);
echo strtr($x, '_', '-'), "\n";
echo strtr(strtolower(substr('HTTP_X_FOO', 5)), '_', '-'), "\n";
echo strtr(substr('xbaab', 1), 'ab', '12'), "\n";
