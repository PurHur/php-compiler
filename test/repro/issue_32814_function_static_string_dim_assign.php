<?php
/**
 * #32814 — function-static string dim write must stay a string under AOT.
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_string_offset
 */
function f(): void
{
    static $s = 'abc';
    $s[1] = 'Z';
    echo $s, "\n";
}
f();
f();
