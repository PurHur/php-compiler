<?php
/**
 * Maintainer gap #32181: static $this is accepted.
 * Zend: compile fatal "Cannot use $this as static variable" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_static_var().
 */
function foo() { static $this; }
echo "accepted\n";
