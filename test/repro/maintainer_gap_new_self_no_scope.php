<?php
/**
 * Maintainer gap #32252: new self in a free function compiles.
 * Zend: compile fatal Cannot use "self" when no class scope is active (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_new() → zend_ensure_valid_class_fetch_type().
 */
function f() {
    return new self;
}
echo "accepted\n";
