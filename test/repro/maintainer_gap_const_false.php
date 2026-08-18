<?php
/**
 * Maintainer gap #32228: file-scope `const false = 1` is accepted.
 * Zend: compile fatal "Cannot redeclare constant 'false'" (rc=255).
 */
const false = 1;
echo "accepted\n";
