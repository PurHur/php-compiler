<?php
/**
 * Maintainer gap #32207: break 2 in one loop leaks Stmt_Break.
 * Zend: compile fatal "Cannot 'break' 2 levels" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_break_continue().
 */
for ($i = 0; $i < 1; $i++) {
    break 2;
}
echo "accepted\n";
