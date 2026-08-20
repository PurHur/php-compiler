<?php
/**
 * #32814 — function-static string default: $s[i]='Z' must mutate the byte (Zend aZc).
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_string_offset
 * @see php-src Zend/zend_vm_def.h ZEND_ASSIGN_DIM
 */
function f(): void
{
    static $s = 'abc';
    $s[1] = 'Z';
    echo $s, "\n";
}
f();
f();
