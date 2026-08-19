<?php
/**
 * #32556 leftover of #32475 — isset()/print on a packed local array.
 * php-src: Zend/zend_execute.c ZEND_ISSET_ISEMPTY_CV; Zend/zend_vm_def.h ZEND_PRINT
 * AOT previously aborted: Not implemented escape operand Isset_ / Print_.
 */
$a = [1];
var_dump(isset($a));
print $a;
echo "\n";
