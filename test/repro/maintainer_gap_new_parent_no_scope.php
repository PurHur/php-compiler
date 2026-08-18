<?php
/**
 * Maintainer gap #32252: new parent in a free function compiles.
 * Zend: compile fatal Cannot use "parent" when no class scope is active (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_new() → zend_ensure_valid_class_fetch_type().
 */
function f() {
    return new parent;
}
echo "accepted\n";
