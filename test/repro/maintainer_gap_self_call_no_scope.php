<?php
/**
 * Maintainer gap #32227: self::foo() in a free function compiles.
 * Zend: compile fatal Cannot use "self" when no class scope is active (rc=255).
 * php-src: Zend/zend_compile.c zend_ensure_valid_class_fetch_type().
 */
function f(): void {
    self::foo();
}
echo "accepted\n";
