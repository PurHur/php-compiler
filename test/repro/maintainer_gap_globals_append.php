<?php
/**
 * Maintainer gap #32253: $GLOBALS[] = 1 is accepted.
 * Zend: compile fatal "Cannot append to $GLOBALS" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_assign_dim().
 */
$GLOBALS[] = 1;
echo "accepted\n";
