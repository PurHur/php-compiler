<?php
/**
 * Maintainer gap #32228: file-scope `const TRUE = 1` preserves source spelling.
 * Zend: compile fatal "Cannot redeclare constant 'TRUE'" (rc=255).
 */
const TRUE = 1;
echo "accepted\n";
