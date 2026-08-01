<?php
/**
 * #26638 — AssignRef of nullsafe chain must be compile-time fatal (Zend wording).
 *
 * Zend: Fatal error: Cannot take reference of a nullsafe chain
 */
$a = null;
$b = &$a?->x;
echo "survived\n";
