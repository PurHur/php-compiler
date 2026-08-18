<?php
/**
 * Maintainer gap #32228: file-scope `const null = 1` is accepted.
 * Zend: compile fatal "Cannot redeclare constant 'null'" (rc=255).
 */
const null = 1;
echo "accepted\n";
