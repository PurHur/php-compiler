<?php
/**
 * Maintainer gap #32228: file-scope `const true = 1` is accepted.
 * Zend: compile fatal "Cannot redeclare constant 'true'" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_const_decl() / zend_get_special_const().
 */
const true = 1;
echo "accepted\n";
