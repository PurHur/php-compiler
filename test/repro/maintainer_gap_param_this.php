<?php
/**
 * Maintainer gap #32179: function foo($this) is accepted.
 * Zend: compile fatal "Cannot use $this as parameter" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_params().
 */
function foo($this) {}
echo "accepted\n";
