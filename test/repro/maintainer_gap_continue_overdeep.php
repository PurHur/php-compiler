<?php
/**
 * Maintainer gap #32207: continue 2 in one loop leaks Stmt_Continue.
 * Zend: compile fatal "Cannot 'continue' 2 levels" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_break_continue().
 */
for ($i = 0; $i < 1; $i++) {
    continue 2;
}
echo "accepted\n";
