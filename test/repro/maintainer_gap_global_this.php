<?php
/**
 * Maintainer gap #32180: global $this is accepted.
 * Zend: compile fatal "Cannot use $this as global variable" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_global_var().
 */
function foo() {
    global $this;
}
echo "accepted\n";
