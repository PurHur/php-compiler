<?php
/**
 * Maintainer gap #32152: function () use ($this) is accepted.
 * Zend: compile fatal "Cannot use $this as lexical variable" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_closure_binding().
 */
$f = function () use ($this) { return 1; };
echo "accepted\n";
