<?php
/**
 * Maintainer gap #32227: parent::foo() in a free function compiles.
 * Zend: compile fatal Cannot use "parent" when no class scope is active (rc=255).
 * php-src: Zend/zend_compile.c zend_ensure_valid_class_fetch_type().
 */
function f(): void {
    parent::foo();
}
echo "accepted\n";
