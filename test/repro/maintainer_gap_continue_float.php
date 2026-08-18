<?php
/**
 * Maintainer gap #32207: continue 1.5 leaks Unimplemented Node Value Type.
 * Zend: compile fatal "'continue' operator accepts only positive integers" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_break_continue().
 */
for ($i = 0; $i < 1; $i++) {
    continue 1.5;
}
echo "accepted\n";
