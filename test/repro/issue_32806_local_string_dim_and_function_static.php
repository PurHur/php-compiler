<?php
/**
 * #32806 — local string dim write must mutate the string under AOT (regression from #32804).
 * Also keeps #32800 function-static packed-array dim writes on the HT path.
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_string_offset / ZEND_ASSIGN_DIM
 */
$s = 'abc';
$s[1] = 'Z';
echo $s, "\n";
echo gettype($s), "\n";

function assign_slot(): void
{
    static $a = ['x'];
    $a[0] = 'y';
    echo $a[0], "\n";
}
assign_slot();
assign_slot();

function concat_slot(): void
{
    static $a = ['x'];
    $a[0] .= 'y';
    echo $a[0], "\n";
}
concat_slot();
concat_slot();
