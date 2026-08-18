<?php
/**
 * Maintainer gap #32229: unset($GLOBALS) is accepted.
 * Zend: compile fatal "$GLOBALS can only be modified using the $GLOBALS[$name] = $value syntax" (rc=255).
 * php-src: Zend/zend_compile.c zend_ensure_writable_variable() / zend_compile_unset().
 */
unset($GLOBALS);
echo "accepted\n";
