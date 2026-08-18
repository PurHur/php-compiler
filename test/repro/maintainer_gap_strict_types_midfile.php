<?php
/**
 * Maintainer gap #32182: mid-file declare(strict_types=1) is accepted.
 * Zend: compile fatal "strict_types declaration must be the very first statement in the script" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_declare() / zend_is_first_statement().
 */
echo 'a';
declare(strict_types=1);
echo 'b';
