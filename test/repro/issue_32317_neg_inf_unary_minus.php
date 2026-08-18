<?php
/**
 * #32317 — unary minus of INF is -INF (zend_operators.c zendi_negate_function).
 */
echo -INF, "\n";
echo -(-INF), "\n";
$x = INF;
echo -$x, "\n";
