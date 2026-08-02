<?php
/**
 * Repro #26904 — AOT wordwrap must match Zend (no segfault after c:main_before_php).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */
echo wordwrap('hello world', 5, "|\n");
