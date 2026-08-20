<?php
/**
 * #32800 — function-static packed string array: $a[0]='y' / $a[0].= must not SEGV under AOT.
 *
 * @see php-src Zend/zend_vm_def.h ZEND_ASSIGN_DIM / ZEND_ASSIGN_DIM_OP
 */
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
